<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\Networks;

final class NetworksTest extends TestCase
{
    public function testAddsAnyEvmChain(): void
    {
        $network = Networks::add(4202, '0x0000000000000000000000000000000000000001', 'Test USD', '1');
        $req = Networks::requirements($network, '1.5', '0x000000000000000000000000000000000000dEaD');

        self::assertSame('eip155:4202', $req['network']);
        self::assertSame('1500000', $req['amount']);
        self::assertSame(['name' => 'Test USD', 'version' => '1'], $req['extra']);
    }

    public function testRejectsABadAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Networks::add(4202, '0x1234', 'Test USD', '1');
    }
}
