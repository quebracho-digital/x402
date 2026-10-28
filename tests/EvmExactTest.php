<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Networks;
use Quebracho\X402\Signer;

final class EvmExactTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private static function req(): array
    {
        return Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
    }

    private static function payment(): array
    {
        $buyer = new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318');
        $scheme = new EvmExact($buyer, fn() => self::NOW);
        return $scheme->pay(['x402Version' => 2, 'resource' => ['url' => 'https://example.test/mcp'], 'accepts' => [self::req()]], self::req());
    }

    public function testAcceptsItsOwnPayment(): void
    {
        self::assertNull((new EvmExact(clock: fn() => self::NOW))->verify(self::payment(), self::req()));
    }

    public static function tampered(): iterable
    {
        yield 'amount' => [fn(array $p) => ['payload' => ['authorization' => ['value' => '1']]] , 'invalid_amount'];
        yield 'recipient' => [fn(array $p) => ['payload' => ['authorization' => ['to' => '0x0000000000000000000000000000000000000001']]], 'invalid_recipient'];
        yield 'payer' => [fn(array $p) => ['payload' => ['authorization' => ['from' => '0x0000000000000000000000000000000000000001']]], 'invalid_signature'];
        yield 'network' => [fn(array $p) => ['accepted' => ['network' => 'eip155:1']], 'unsupported_scheme'];
        yield 'garbage' => [fn(array $p) => ['payload' => ['signature' => 42]], 'invalid_payload'];
    }

    #[DataProvider('tampered')]
    public function testRejectsTamperedPayments(\Closure $patch, string $reason): void
    {
        $payment = array_replace_recursive(self::payment(), $patch(self::payment()));
        self::assertSame($reason, (new EvmExact(clock: fn() => self::NOW))->verify($payment, self::req()));
    }

    public function testRejectsExpiredPayments(): void
    {
        self::assertSame('expired', (new EvmExact(clock: fn() => self::NOW + 3600))->verify(self::payment(), self::req()));
    }
}
