<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Message\ServerRequestInterface;

/**
 * X402Middleware's and X402McpMiddleware's $sensitive, as a dictionary of rules: a paid request whose answer the
 * payment ledger must not keep (only its state). Sensitive when any rule marks it; no rule matching means not
 * sensitive.
 *
 *     ['kind' => 'header', 'name' => 'Cache-Control', 'value' => 'no-store', 'unless_header' => 'X-Replay-Ok']
 *     ['kind' => 'argument', 'name' => 'sensitive', 'value' => true]
 *     ['kind' => 'tool', 'name' => 'analyze_terms']
 *
 * `header` rules read an HTTP request (X402Middleware); `argument` and `tool` rules read MCP tools/call params
 * (X402McpMiddleware). A rule that cannot read the subject does not match it.
 *
 * A bad rule throws when the rules are built: build them at deploy (a cache warmer, a check command), so a
 * typo fails the deploy and not a paid request.
 *
 * @phpstan-type Rule array{kind: 'header', name: string, value: string, unless_header?: string}|array{kind: 'argument', name: string, value: scalar}|array{kind: 'tool', name: string}
 */
final class SensitiveRules
{
    private const array KINDS = ['header', 'argument', 'tool'];

    /** @var list<Rule> */
    private readonly array $rules;

    /**
     * @param list<array<string, mixed>> $rules the rules, in the shape above
     * @param list<string>               $tools tools whose answers are never kept: one `tool` rule per name
     *
     * @throws \InvalidArgumentException naming each bad rule by its position
     */
    public function __construct(array $rules, array $tools = [])
    {
        $merged = self::merge($tools, $rules);
        self::validate($merged);
        $this->rules = $merged;
    }

    /**
     * The rules plus one `tool` rule per name in $tools.
     *
     * @param list<string>               $tools
     * @param list<array<string, mixed>> $rules
     *
     * @return list<array<string, mixed>>
     */
    public static function merge(array $tools, array $rules): array
    {
        return [...$rules, ...array_map(static fn (string $name) => ['kind' => 'tool', 'name' => $name], $tools)];
    }

    /**
     * Deploy check: every rule well formed, none twice, every `tool` rule a served tool.
     *
     * @param list<string>               $tools
     * @param list<array<string, mixed>> $rules
     * @param array<string, mixed>       $served every tool the server serves, by name
     *
     * @throws \InvalidArgumentException naming each problem
     */
    public static function check(array $tools, array $rules, array $served): void
    {
        $rules = self::merge($tools, $rules);
        self::validate($rules);
        $unknown = [];
        foreach ($rules as $rule) {
            if ('tool' === $rule['kind'] && !\array_key_exists($rule['name'], $served)) {
                $unknown[] = $rule['name'];
            }
        }
        if ([] !== $unknown) {
            throw new \InvalidArgumentException(\sprintf('sensitive tool rule names no served tool: %s; those answers would be kept in the ledger.', implode(', ', $unknown)));
        }
    }

    /**
     * @param list<array<string, mixed>> $rules
     *
     * @throws \InvalidArgumentException naming each bad rule by its position
     *
     * @phpstan-assert list<Rule> $rules
     */
    public static function validate(array $rules): void
    {
        $errors = [];
        $seen = [];
        foreach ($rules as $i => $rule) {
            $at = "rule $i";
            $kind = $rule['kind'] ?? null;
            $name = $rule['name'] ?? null;
            if (!\in_array($kind, self::KINDS, true)) {
                $errors[] = \sprintf('%s: unknown kind %s (one of %s)', $at, json_encode($kind), implode(', ', self::KINDS));
                continue;
            }
            if (!\is_string($name) || '' === trim($name)) {
                $errors[] = "$at ($kind): name must be a non-empty string";
                continue;
            }
            $allowed = match ($kind) {
                'header' => ['kind', 'name', 'value', 'unless_header'],
                'argument' => ['kind', 'name', 'value'],
                'tool' => ['kind', 'name'],
            };
            if ([] !== $extra = array_diff(array_keys($rule), $allowed)) {
                $errors[] = \sprintf('%s (%s %s): unexpected key %s', $at, $kind, $name, implode(', ', $extra));
            }
            $value = $rule['value'] ?? null;
            if ('header' === $kind && (!\is_string($value) || '' === trim($value) || str_contains($value, ','))) {
                $errors[] = "$at (header $name): value must be one directive, a non-empty string without commas";
            }
            if ('argument' === $kind && !\is_scalar($value)) {
                $errors[] = "$at (argument $name): value must be given (true, a number or a string)";
            }
            $unless = $rule['unless_header'] ?? null;
            if (\array_key_exists('unless_header', $rule) && (!\is_string($unless) || '' === trim($unless))) {
                $errors[] = "$at (header $name): unless_header must be a non-empty header name";
            }
            // Header names and directives are case-insensitive; arguments and tools are not.
            $key = 'header' === $kind ? strtolower("header\0$name\0".(\is_string($value) ? trim($value) : '')) : $kind."\0".$name."\0".var_export($value, true);
            if (isset($seen[$key])) {
                $errors[] = \sprintf('%s (%s %s): duplicate of rule %d', $at, $kind, $name, $seen[$key]);
            }
            $seen[$key] ??= $i;
        }
        if ([] !== $errors) {
            throw new \InvalidArgumentException('x402 sensitive rules: '.implode('; ', $errors).'.');
        }
    }

    /** @param ServerRequestInterface|array<string, mixed> $subject an HTTP request, or MCP tools/call params */
    public function __invoke(ServerRequestInterface|array $subject): bool
    {
        foreach ($this->rules as $rule) {
            if (self::matches($rule, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether these rules mark a tools/call, given its tool name and arguments. A rule that fails counts as a
     * mark, as in the middlewares: the safe side only drops a copy of the answer.
     *
     * @param array<string, mixed> $args
     */
    public function marks(string $name, array $args): bool
    {
        try {
            return $this(['name' => $name, 'arguments' => $args]);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param Rule                                        $rule
     * @param ServerRequestInterface|array<string, mixed> $subject
     */
    private static function matches(array $rule, ServerRequestInterface|array $subject): bool
    {
        if ($subject instanceof ServerRequestInterface) {
            if ('header' !== $rule['kind'] || (isset($rule['unless_header']) && $subject->hasHeader($rule['unless_header']))) {
                return false;
            }

            return \in_array(strtolower(trim($rule['value'])), self::directives($subject->getHeaderLine($rule['name'])), true);
        }

        return match ($rule['kind']) {
            'tool' => ($subject['name'] ?? null) === $rule['name'],
            'argument' => \is_array($subject['arguments'] ?? null) && \array_key_exists($rule['name'], $subject['arguments']) && $subject['arguments'][$rule['name']] === $rule['value'],
            default => false,
        };
    }

    /**
     * Directive names of a Cache-Control-like header line: "Max-Age=0, NO-STORE" gives ['max-age', 'no-store'].
     * ponytail: splits on every comma, so a quoted argument with commas (no-cache="a, no-store, b") yields stray tokens;
     * at worst one marks a request that did not ask, which only drops the ledger's copy (the safe side). A quoted-string
     * parser if that ever matters.
     *
     * @return list<string>
     */
    public static function directives(string $line): array
    {
        return array_values(array_filter(array_map(
            static fn (string $part) => strtolower(trim(explode('=', $part, 2)[0])),
            explode(',', $line),
        ), static fn (string $d) => '' !== $d));
    }
}
