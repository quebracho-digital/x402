<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\Budget;
use Quebracho\X402\Networks;

final class BudgetTest extends TestCase
{
    private static function offer(): array
    {
        return Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
    }

    public function testSignsAnHonestOffer(): void
    {
        self::assertTrue(Budget::usd('eip155:84532', '0.05')->allows(self::offer()));
    }

    /** What a hostile seller can put in its PaymentRequired. */
    public static function hostile(): iterable
    {
        yield 'other token' => [['asset' => '0x29f2D40B0605204364af54EC677bD022dA425d03']];
        yield 'other EIP-712 domain' => [['extra' => ['name' => 'Fake', 'version' => '2']]];
        yield 'unknown network' => [['network' => 'eip155:1']];
        yield 'ten-year validity' => [['maxTimeoutSeconds' => 315360000]];
        yield 'validity as string' => [['maxTimeoutSeconds' => '60']];
        yield 'not a number' => [['amount' => 'abc']];
        yield 'exponent' => [['amount' => '1e3']];
        yield 'negative' => [['amount' => '-10000']];
        yield 'zero' => [['amount' => '0']];
        yield 'above the cap' => [['amount' => '50001']];
        yield 'payTo without 0x' => [['payTo' => '000000000000000000000000000000000000dEaD']];
        yield 'payTo zero-padded' => [['payTo' => '0x00000000000000000000000000000000000000dEaD']];
        yield 'payTo as array' => [['payTo' => ['0x000000000000000000000000000000000000dEaD']]];
        yield 'beyond uint256' => [['amount' => str_repeat('9', 79)]];
    }

    #[DataProvider('hostile')]
    public function testRefusesAHostileOffer(array $patch): void
    {
        self::assertFalse(Budget::usd('eip155:84532', '0.05')->allows(array_replace(self::offer(), $patch)));
    }
}
