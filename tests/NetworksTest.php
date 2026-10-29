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

    public function testCatalogue(): void
    {
        Networks::reset();
        self::assertTrue(Networks::has('eip155:84532'));
        self::assertNull(Networks::find('eip155:1'));
        $sol = Networks::addSolana('5eykt4UsFv8P8NJdTREpY1vzqKqZKvdpKuc147dw2N9d', 'EPjFWDvwVrfSoUeVmsKoc8MPa6tdozRmrgHgDt9uhAa7', 'Solana (USDC)');
        self::assertSame('solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdpKuc147dw2N9d', $sol);
        self::assertArrayHasKey($sol, Networks::all());
        Networks::reset();
        self::assertFalse(Networks::has($sol));
    }

    public function testPayToIsCheckedPerFamily(): void
    {
        self::assertSame('0x000000000000000000000000000000000000dEaD', Networks::requirements('eip155:84532', '1', '0x000000000000000000000000000000000000dead')['payTo']);
        self::assertSame('CKPKJWNdJEqa81x7CkZ14BVPiY6y16Sxs7owznqtWYp5', Networks::requirements('solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1', '1', 'CKPKJWNdJEqa81x7CkZ14BVPiY6y16Sxs7owznqtWYp5')['payTo']);
        foreach ([['eip155:84532', 'CKPKJWNdJEqa81x7CkZ14BVPiY6y16Sxs7owznqtWYp5'], ['solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1', '0x000000000000000000000000000000000000dEaD']] as [$network, $payTo]) {
            try {
                Networks::requirements($network, '1', $payTo);
                self::fail($network);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
