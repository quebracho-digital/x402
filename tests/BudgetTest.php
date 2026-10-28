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

    /** A budget that spent one signed, unsettled payment valid until t=1000. */
    private static function withUnsettled(?\Closure $used, int &$now): Budget
    {
        $budget = new Budget(['eip155:84532' => '50000'], $used, function () use (&$now) { return $now; });
        $budget->spend(self::offer());
        $budget->unsettled(self::offer(), ['payload' => ['authorization' => ['from' => '0xabc', 'nonce' => '0x01', 'validBefore' => '1000']]]);
        return $budget;
    }

    public function testFreesAnExpiredSignatureTheChainNeverUsed(): void
    {
        $now = 1000;
        $budget = self::withUnsettled(fn(string $network, string $from, string $nonce) => false, $now);
        $budget->reclaim();
        self::assertSame('10000', $budget->spent('eip155:84532'), 'still valid: the seller may settle it yet');

        $now = 1060;
        $budget->reclaim();
        self::assertSame('10000', $budget->spent('eip155:84532'), 'within the clock-skew margin');

        $now = 1061;
        $budget->reclaim();
        self::assertSame('0', $budget->spent('eip155:84532'));
        $budget->reclaim();
        self::assertSame('0', $budget->spent('eip155:84532'), 'freed once');
    }

    public function testKeepsWhatTheChainUsedOrCannotTell(): void
    {
        $now = 2000;
        $used = self::withUnsettled(fn() => true, $now);
        $used->reclaim();
        self::assertSame('10000', $used->spent('eip155:84532'));

        $down = self::withUnsettled(fn() => throw new \RuntimeException('RPC down'), $now);
        $down->reclaim();
        self::assertSame('10000', $down->spent('eip155:84532'));

        $none = self::withUnsettled(null, $now);
        $none->reclaim();
        self::assertSame('10000', $none->spent('eip155:84532'));
    }
}
