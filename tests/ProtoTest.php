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
