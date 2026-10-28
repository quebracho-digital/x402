<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Settlement;
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
        yield 'amount' => [fn(array $p) => ['payload' => ['authorization' => ['value' => '1']]] , 'invalid_exact_evm_payload_authorization_value_mismatch'];
        yield 'recipient' => [fn(array $p) => ['payload' => ['authorization' => ['to' => '0x0000000000000000000000000000000000000001']]], 'invalid_exact_evm_payload_recipient_mismatch'];
        yield 'payer' => [fn(array $p) => ['payload' => ['authorization' => ['from' => '0x0000000000000000000000000000000000000001']]], 'invalid_exact_evm_payload_signature'];
        yield 'network' => [fn(array $p) => ['accepted' => ['network' => 'eip155:1']], 'invalid_network'];
        yield 'array nonce' => [fn(array $p) => ['payload' => ['authorization' => ['nonce' => ['x']]]], 'invalid_payload'];
        yield 'echoed payTo' => [fn(array $p) => ['accepted' => ['payTo' => '0x0000000000000000000000000000000000000001']], 'invalid_payload'];
        yield 'version' => [fn(array $p) => ['x402Version' => 1], 'invalid_x402_version'];
        yield 'nonce without 0x' => [fn(array $p) => ['payload' => ['authorization' => ['nonce' => substr($p['payload']['authorization']['nonce'], 2)]]], 'invalid_payload'];
        yield 'nonce not hex' => [fn(array $p) => ['payload' => ['authorization' => ['nonce' => 'zz']]], 'invalid_payload'];
        yield 'validAfter not decimal' => [fn(array $p) => ['payload' => ['authorization' => ['validAfter' => '0abc']]], 'invalid_payload'];
        yield 'value with a leading zero' => [fn(array $p) => ['payload' => ['authorization' => ['value' => '010000']]], 'invalid_payload'];
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
        self::assertSame('invalid_exact_evm_payload_authorization_valid_before', (new EvmExact(clock: fn() => self::NOW + 3600))->verify(self::payment(), self::req()));
    }

    public function testRejectsAPaymentAboutToExpire(): void
    {
        $validBefore = (int) self::payment()['payload']['authorization']['validBefore'];
        self::assertSame('invalid_exact_evm_payload_authorization_valid_before', (new EvmExact(clock: fn() => $validBefore - 5))->verify(self::payment(), self::req()));
    }

    public function testRejectsRequirementsWithoutTheTokenDomain(): void
    {
        $req = self::req();
        unset($req['extra']['version']);
        self::assertSame('invalid_payment_requirements', (new EvmExact(clock: fn() => self::NOW))->verify(self::payment(), $req));
    }

    public function testRejectsAMalleatedHighSSignature(): void
    {
        $payment = self::payment();
        $sig = substr($payment['payload']['signature'], 2);
        $n = gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141', 16);
        $highS = str_pad(gmp_strval(gmp_sub($n, gmp_init(substr($sig, 64, 64), 16)), 16), 64, '0', STR_PAD_LEFT);
        $v = hexdec(substr($sig, 128, 2)) === 27 ? '1c' : '1b';
        $payment['payload']['signature'] = '0x' . substr($sig, 0, 64) . $highS . $v;
        self::assertSame('invalid_exact_evm_payload_signature', (new EvmExact(clock: fn() => self::NOW))->verify($payment, self::req()));
    }

    public function testRejectsAValidityWindowLongerThanTheOffer(): void
    {
        $payment = self::payment();
        $payment['payload']['authorization']['validBefore'] = '99999999999';
        self::assertSame('invalid_exact_evm_payload_authorization_valid_before', (new EvmExact(clock: fn() => self::NOW))->verify($payment, self::req()));
    }

    public function testOneSignatureIsOneClaimHoweverTheNonceIsSpelled(): void
    {
        $payment = self::payment();
        $upper = $payment;
        $upper['payload']['authorization']['nonce'] = '0x'.strtoupper(substr($payment['payload']['authorization']['nonce'], 2));
        self::assertNull((new EvmExact(clock: fn() => self::NOW))->verify($upper, self::req()), 'same bytes, same signature');
        self::assertSame(Settlement::key($payment), Settlement::key($upper));
    }
}
