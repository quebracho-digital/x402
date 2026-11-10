<?php

declare(strict_types=1);

/**
 * Writes the public API inventory of a release, the fixture tests/CompatibilityTest.php reads.
 *
 *   php bin/api-inventory.php <src-dir> <tag> <commit> > tests/compat/<tag>-api.json
 *
 * Run it against a checkout of the tag, not against the working tree: the fixture must describe
 * what was published, and the test compares today's classes with it.
 */
if ($argc < 4) {
    fwrite(STDERR, "usage: php bin/api-inventory.php <src-dir> <tag> <commit>\n");
    exit(2);
}

[, $src, $tag, $commit] = $argv;
$src = rtrim($src, '/');

if (!is_dir($src)) {
    fwrite(STDERR, "not a directory: $src\n");
    exit(2);
}

require __DIR__.'/../vendor/autoload.php';

// The target checkout's classes win over the autoloader's, which points at this working tree.
spl_autoload_register(static function (string $class) use ($src): void {
    $prefix = 'Quebracho\\X402\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = $src.'/'.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';
    if (is_file($file)) {
        require $file;
    }
}, true, true);

/** @return list<string> */
function declaredClasses(string $src): array
{
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ('php' !== $file->getExtension()) {
            continue;
        }
        $tokens = token_get_all((string) file_get_contents($file->getPathname()));
        $namespace = '';
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }
            if (\T_NAMESPACE === $token[0]) {
                $namespace = '';
                for ($j = $i + 1; $j < $count; ++$j) {
                    $next = $tokens[$j];
                    if (\is_array($next) && \in_array($next[0], [\T_STRING, \T_NAME_QUALIFIED], true)) {
                        $namespace .= $next[1];
                    } elseif (';' === $next || '{' === $next) {
                        break;
                    }
                }
                continue;
            }
            if (!\in_array($token[0], [\T_CLASS, \T_INTERFACE, \T_ENUM, \T_TRAIT], true)) {
                continue;
            }
            // An anonymous class (new class ...) has no name to reflect.
            $previous = null;
            for ($j = $i - 1; $j >= 0; --$j) {
                if (\is_array($tokens[$j]) && \T_WHITESPACE === $tokens[$j][0]) {
                    continue;
                }
                $previous = $tokens[$j];
                break;
            }
            if (\is_array($previous) && \T_NEW === $previous[0]) {
                continue;
            }
            for ($j = $i + 1; $j < $count; ++$j) {
                if (\is_array($tokens[$j]) && \T_WHITESPACE === $tokens[$j][0]) {
                    continue;
                }
                if (\is_array($tokens[$j]) && \T_STRING === $tokens[$j][0]) {
                    $found[] = ltrim($namespace.'\\'.$tokens[$j][1], '\\');
                }
                break;
            }
        }
    }
    sort($found);

    return array_values(array_unique($found));
}

/** @return list<array<string, mixed>> */
function params(?ReflectionFunctionAbstract $function): array
{
    if (null === $function) {
        return [];
    }
    $out = [];
    foreach ($function->getParameters() as $p) {
        $out[] = [
            'name' => $p->getName(),
            'position' => $p->getPosition(),
            'type' => null === $p->getType() ? null : (string) $p->getType(),
            'optional' => $p->isOptional(),
            'hasDefault' => $p->isDefaultValueAvailable(),
            'default' => $p->isDefaultValueAvailable() ? json_encode(jsonSafe($p->getDefaultValue()), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR) : null,
            'variadic' => $p->isVariadic(),
            'byRef' => $p->isPassedByReference(),
            'promoted' => $p->isPromoted(),
        ];
    }

    return $out;
}

function jsonSafe(mixed $value): mixed
{
    if ($value instanceof UnitEnum) {
        return $value instanceof BackedEnum ? $value->value : $value->name;
    }
    if (\is_array($value)) {
        return array_map('jsonSafe', $value);
    }
    if (\is_object($value)) {
        return $value::class;
    }

    return $value;
}

/** @return array<string, mixed> */
function method(ReflectionMethod $m): array
{
    return [
        'declaringClass' => $m->getDeclaringClass()->getName(),
        'static' => $m->isStatic(),
        'abstract' => $m->isAbstract(),
        'returnType' => null === $m->getReturnType() ? null : (string) $m->getReturnType(),
        'params' => params($m),
    ];
}

/** @return array<string, mixed> */
function describe(string $fqn): array
{
    $rc = new ReflectionClass($fqn);

    $constants = [];
    foreach ($rc->getReflectionConstants() as $c) {
        if (!$c->isPublic()) {
            continue;
        }
        $constants[$c->getName()] = [
            'value' => $c->isEnumCase() ? $c->getName() : jsonSafe($c->getValue()),
            'declaringClass' => $c->getDeclaringClass()->getName(),
        ];
    }
    ksort($constants);

    $methods = [];
    foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        $methods[$m->getName()] = method($m);
    }
    ksort($methods);

    $properties = [];
    foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $p) {
        $properties[$p->getName()] = [
            'type' => null === $p->getType() ? null : (string) $p->getType(),
            'static' => $p->isStatic(),
            'readonly' => $p->isReadOnly(),
        ];
    }
    ksort($properties);

    $constructor = $rc->getConstructor();

    return [
        'kind' => $rc->isInterface() ? 'interface' : ($rc->isEnum() ? 'enum' : 'class'),
        'final' => $rc->isFinal(),
        'abstract' => $rc->isAbstract(),
        'interfaces' => $rc->getInterfaceNames(),
        'constants' => $constants,
        'constructor' => null === $constructor ? null : method($constructor),
        'methods' => $methods,
        'properties' => $properties,
    ];
}

$classes = [];
foreach (declaredClasses($src) as $fqn) {
    if (!class_exists($fqn) && !interface_exists($fqn) && !enum_exists($fqn)) {
        fwrite(STDERR, "cannot load $fqn\n");
        exit(1);
    }
    $classes[$fqn] = describe($fqn);
}

$inventory = [
    'library' => 'quebracho/x402',
    'source' => "github quebracho-digital/x402 tag $tag (commit $commit)",
    'classes' => $classes,
];

echo json_encode($inventory, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR), "\n";
