<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The docblocks of the library say what the code takes: the middlewares and facilitators document their
 * closures and options there and nowhere else, so a docblock out of step is a wrong manual.
 *
 * For every method and function-like of every class in src/:
 * - each `@param` names a parameter the method has (a renamed or removed parameter leaves a stale line);
 * - no parameter is documented twice;
 * - `@deprecated` appears at most once (a formatter that splits a `@param` leaves several, with no name);
 * - a docblock with a `@return` does not sit on a constructor.
 */
final class DocblockTest extends TestCase
{
    /** @return iterable<string, array{\ReflectionMethod}> */
    public static function methods(): iterable
    {
        $src = \dirname(__DIR__).'/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $class = 'Quebracho\\X402\\'.str_replace('/', '\\', substr($file->getPathname(), \strlen($src) + 1, -4));
            self::assertTrue(class_exists($class) || interface_exists($class) || enum_exists($class), "$class is not where its file says");
            foreach ((new \ReflectionClass($class))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() === $class && false !== $method->getDocComment()) {
                    yield "$class::{$method->getName()}" => [$method];
                }
            }
        }
    }

    #[DataProvider('methods')]
    public function testADocblockDescribesTheMethodItSitsOn(\ReflectionMethod $method): void
    {
        $doc = (string) $method->getDocComment();
        $where = $method->getDeclaringClass()->getName().'::'.$method->getName().'()';
        $parameters = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters());

        // The name is the first $word after the tag: a closure's signature may hold others further on.
        preg_match_all('/@param\b[^\n$]*\$(\w+)/', $doc, $m);
        foreach ($m[1] as $name) {
            self::assertContains($name, $parameters, "$where documents a parameter \$$name it does not have");
        }
        self::assertSame(array_unique($m[1]), $m[1], "$where documents a parameter twice");
        self::assertSame(substr_count($doc, '@param'), \count($m[1]), "$where has a @param without a parameter name");
        self::assertLessThanOrEqual(1, preg_match_all('/@deprecated\b/', $doc), "$where says @deprecated more than once: a @param lost its name?");
        if ($method->isConstructor()) {
            self::assertStringNotContainsString('@return', $doc, "$where: a constructor returns nothing");
        }
    }
}
