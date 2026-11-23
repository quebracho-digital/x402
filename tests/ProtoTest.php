<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\Proto;

final class ProtoTest extends TestCase
{
    public function testAVarintThatOverflowsPhpIntIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Proto::parse("\x0a".str_repeat("\xff", 9)."\x01");
    }

    public function testAVarintWiderThan64BitsIsRefused(): void
    {
        // 2^64: its tenth byte is 0x02, which a 64-bit shift would drop and read as 0.
        $this->expectException(\InvalidArgumentException::class);
        Proto::parse("\x08".str_repeat("\x80", 9)."\x02");
    }

    public function testTheLargestPositiveVarintIsRead(): void
    {
        $this->assertSame(\PHP_INT_MAX, Proto::first(Proto::parse("\x08".str_repeat("\xff", 8)."\x7f"), 1));
    }

    public function testALengthPastTheBodyIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Proto::parse("\x0a\x05abc");
    }

    public function testATruncatedVarintIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Proto::parse("\x80");
    }
}
