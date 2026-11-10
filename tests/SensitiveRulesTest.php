<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\SensitiveRules;

final class SensitiveRulesTest extends TestCase
{
    private const SERVED = ['wisdom' => ['job' => null, 'schema' => '{}'], 'analyze_terms' => ['job' => null, 'schema' => '{}']];

    /** @return list<array<string, mixed>> the rules an application would ship: no-store, Pragma, an argument */
    private static function shipped(): array
    {
        return [
            ['kind' => 'header', 'name' => 'Cache-Control', 'value' => 'no-store'],
            ['kind' => 'header', 'name' => 'Pragma', 'value' => 'no-cache', 'unless_header' => 'Cache-Control'],
            ['kind' => 'argument', 'name' => 'sensitive', 'value' => true],
        ];
    }

    /** @param array<string, string> $headers */
    private static function http(array $headers): ServerRequest
    {
        return new ServerRequest('POST', '/paid', $headers);
    }

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function headers(): iterable
    {
        yield 'Cache-Control: no-store' => [['Cache-Control' => 'no-store'], true];
        yield 'several directives, spaces, case' => [['Cache-Control' => 'max-age=0 ,  No-Store'], true];
        yield 'Cache-Control: max-age=60' => [['Cache-Control' => 'max-age=60'], false];
        yield 'a substring is not the directive' => [['Cache-Control' => 'no-store-ish, x-no-store'], false];
        yield 'Pragma without Cache-Control' => [['Pragma' => 'no-cache'], true];
        yield 'Cache-Control wins over Pragma' => [['Pragma' => 'no-cache', 'Cache-Control' => 'max-age=60'], false];
        yield 'no header at all' => [[], false];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('headers')]
    public function testHeaders(array $headers, bool $sensitive): void
    {
        self::assertSame($sensitive, (new SensitiveRules(self::shipped()))(self::http($headers)));
    }

    public function testARuleValueIsTrimmed(): void
    {
        $rules = new SensitiveRules([['kind' => 'header', 'name' => 'Cache-Control', 'value' => ' no-store ']]);

        self::assertTrue($rules(self::http(['Cache-Control' => 'no-store'])));
        self::assertTrue($rules(self::http(['Cache-Control' => ' max-age=0 , NO-STORE'])));
        self::assertFalse($rules(self::http(['Cache-Control' => 'max-age=0'])));
        self::assertFalse($rules(self::http(['Cache-Control' => 'no-store-ish'])));
    }

    public function testMcpArgumentAndTool(): void
    {
        $rules = new SensitiveRules(self::shipped(), ['analyze_terms']);

        self::assertTrue($rules(['name' => 'wisdom', 'arguments' => ['sensitive' => true]]));
        self::assertFalse($rules(['name' => 'wisdom', 'arguments' => ['sensitive' => 'true']]));
        self::assertTrue($rules(['name' => 'analyze_terms', 'arguments' => []]));
        self::assertFalse($rules(['name' => 'wisdom', 'arguments' => []]));
        // A header rule does not read MCP params, an MCP rule does not read an HTTP request.
        self::assertFalse($rules(['name' => 'wisdom', 'arguments' => ['Cache-Control' => 'no-store']]));
        self::assertFalse($rules(self::http([])));
    }

    public function testNoRuleIsNotSensitive(): void
    {
        $rules = new SensitiveRules([]);
        self::assertFalse($rules(self::http(['Cache-Control' => 'no-store'])));
        self::assertFalse($rules(['name' => 'analyze_terms', 'arguments' => ['sensitive' => true]]));
    }

    public function testTheShippedRulesAreValid(): void
    {
        SensitiveRules::check([], self::shipped(), self::SERVED);
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function invalid(): iterable
    {
        yield 'unknown kind' => [[['kind' => 'cookie', 'name' => 'x']], 'rule 0: unknown kind "cookie"'];
        yield 'empty name' => [[['kind' => 'tool', 'name' => ' ']], 'name must be a non-empty string'];
        yield 'header without value' => [[['kind' => 'header', 'name' => 'Cache-Control']], 'rule 0 (header Cache-Control): value must be one directive'];
        yield 'argument without value' => [[['kind' => 'argument', 'name' => 'sensitive']], 'rule 0 (argument sensitive): value must be given'];
        yield 'tool with a value' => [[['kind' => 'tool', 'name' => 'wisdom', 'value' => true]], 'unexpected key value'];
        yield 'duplicate, other case' => [[['kind' => 'header', 'name' => 'Cache-Control', 'value' => 'no-store'], ['kind' => 'header', 'name' => 'cache-control', 'value' => 'NO-STORE']], 'rule 1 (header cache-control): duplicate of rule 0'];
    }

    /** @param list<array<string, mixed>> $rules */
    #[DataProvider('invalid')]
    public function testAnInvalidRuleFailsWithAClearMessage(array $rules, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        SensitiveRules::validate($rules);
    }

    public function testAToolInTheYamlAndInTheEnvIsADuplicate(): void
    {
        $this->expectExceptionMessage('duplicate of rule 0');
        SensitiveRules::check(['wisdom'], [['kind' => 'tool', 'name' => 'wisdom']], self::SERVED);
    }

    public function testAToolRuleThatNamesNoServedToolFailsTheCheck(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no served tool: anaylze_terms');
        SensitiveRules::check([], [...self::shipped(), ['kind' => 'tool', 'name' => 'anaylze_terms']], self::SERVED);
    }

    public function testABadRuleFailsWhenTheRulesAreBuilt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('x402 sensitive rules: rule 0 (header Pragma)');
        new SensitiveRules([['kind' => 'header', 'name' => 'Pragma']]);
    }

    public function testMarksCountsARuleThatCannotAnswerAsSensitive(): void
    {
        $rules = new SensitiveRules(self::shipped());
        self::assertTrue($rules->marks('wisdom', ['sensitive' => true]));
        self::assertFalse($rules->marks('wisdom', []));
    }
}
