<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Quebracho\X402\X402Exception;

/**
 * The rules of CONTRIBUTING.md that a machine can check, checked on every class of src/. A rule that is
 * only written down gets broken; these fail the build instead.
 */
final class ArchitectureTest extends TestCase
{
    /** @return iterable<string, array{\ReflectionClass<object>, string}> every class of src/ and its source */
    public static function classes(): iterable
    {
        $src = \dirname(__DIR__).'/src';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ('php' === $file->getExtension()) {
                $class = 'Quebracho\\X402\\'.str_replace('/', '\\', substr($file->getPathname(), \strlen($src) + 1, -4));
                yield $class => [new \ReflectionClass($class), (string) file_get_contents($file->getPathname())];
            }
        }
    }

    /** @param \ReflectionClass<object> $class */
    #[DataProvider('classes')]
    public function testEveryFileIsStrict(\ReflectionClass $class, string $source): void
    {
        self::assertStringContainsString("declare(strict_types=1);\n", $source, "{$class->getName()}: no strict types");
    }

    /** @param \ReflectionClass<object> $class */
    #[DataProvider('classes')]
    public function testAClassIsFinal(\ReflectionClass $class, string $source = ''): void
    {
        // Extended through interfaces and composition, never by inheritance: a subclass would depend on internals.
        self::assertTrue($class->isFinal() || $class->isInterface() || $class->isEnum() || $class->isAbstract(), "{$class->getName()} is not final");
    }

    /** @param \ReflectionClass<object> $class */
    #[DataProvider('classes')]
    public function testEveryConstantIsTyped(\ReflectionClass $class, string $source = ''): void
    {
        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() === $class->getName() && !$constant->isEnumCase()) {
                self::assertTrue($constant->hasType(), "{$class->getName()}::{$constant->getName()} has no type");
            }
        }
        $this->addToAssertionCount(1);
    }

    /** @param \ReflectionClass<object> $class */
    #[DataProvider('classes')]
    public function testEveryExceptionOfTheLibraryIsMarked(\ReflectionClass $class, string $source = ''): void
    {
        if ($class->isSubclassOf(\Throwable::class) && !$class->isInterface()) {
            self::assertTrue($class->implementsInterface(X402Exception::class), "{$class->getName()} does not implement X402Exception");
        }
        $this->addToAssertionCount(1);
    }

    /** @param \ReflectionClass<object> $class */
    #[DataProvider('classes')]
    public function testAClockIsAPsr20ClockOrAClosure(\ReflectionClass $class, string $source = ''): void
    {
        // NearExact's "clock" is a block height, not a time: it stays a closure.
        foreach ($class->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ('clock' === $parameter->getName() && !str_ends_with($class->getName(), 'NearExact')) {
                self::assertStringContainsString(ClockInterface::class, (string) $parameter->getType(), "{$class->getName()} takes a clock that cannot be a PSR-20 clock");
            }
        }
        $this->addToAssertionCount(1);
    }

    /** @param \ReflectionClass<object> $class */
    #[DataProvider('classes')]
    public function testNoSecretIsShownInAStackTrace(\ReflectionClass $class, string $source = ''): void
    {
        foreach ($class->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                if (1 === preg_match('/^(secretKey|privateKey|apiKey|bearer|password)$/', $parameter->getName())) {
                    self::assertNotSame([], $parameter->getAttributes(\SensitiveParameter::class), "{$class->getName()}::{$method->getName()}(\${$parameter->getName()}) is not #[\\SensitiveParameter]");
                }
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testTheGuidesNameNoInternalClass(): void
    {
        $root = \dirname(__DIR__);
        // UPGRADE.md is left out: it is where a reader is told to move off an internal name.
        $guides = [$root.'/README.md', ...(glob($root.'/docs/*.md') ?: [])];
        foreach (self::classes() as [$class]) {
            if (!str_contains((string) $class->getDocComment(), '@internal')) {
                continue;
            }
            foreach ($guides as $guide) {
                self::assertDoesNotMatchRegularExpression('/`'.preg_quote($class->getShortName(), '/').'(::|`)/', (string) file_get_contents($guide), basename($guide)." sends readers to {$class->getName()}, which is internal");
            }
        }
    }

    public function testThePublicApiNeedsNoInternalClass(): void
    {
        // What an integrator writes against (a constant to compare with, a helper to call) lives on a public class.
        $internal = [];
        foreach (self::classes() as [$class]) {
            if (str_contains((string) $class->getDocComment(), '@internal')) {
                $internal[] = $class->getShortName();
            }
        }
        foreach (self::classes() as [$class, $source]) {
            if (\in_array($class->getShortName(), $internal, true)) {
                continue;
            }
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                foreach ([...array_map(static fn (\ReflectionParameter $p) => (string) $p->getType(), $method->getParameters()), (string) $method->getReturnType()] as $type) {
                    foreach ($internal as $name) {
                        self::assertDoesNotMatchRegularExpression('/(^|[\\\\|?])'.$name.'($|\|)/', $type, "{$class->getName()}::{$method->getName()}() exposes the internal $name");
                    }
                }
            }
        }
    }
}
