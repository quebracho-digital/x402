<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\NanoExact;
use Quebracho\X402\Schemes;
use Quebracho\X402\Settlement;
use Quebracho\X402\SpendsOnce;

/**
 * Addresses pinned against the genesis account; the representative against xno-call-binding's
 * commitment_rep_account(ORDER_ID, PAY_TO, AMOUNT) (binding_options.py, computed with Python's hashlib).
 */
final class NanoExactTest extends TestCase
{
    private const string GENESIS_KEY = 'E89208DD038FBB269987689621D52292AE9C35941A7484756ECCED92A65093BA';
    private const string PAY_TO = 'nano_3t6k35gi95xu6tergt6p69ck76ogmitsa8mnijtpxm9fkcm736xtoncuohr3';
    private const string ORDER_ID = '0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20';
    // 0.001 XNO: 10^27 raw.
    private const string AMOUNT = '1000000000000000000000000000';
    private const string REPRESENTATIVE = 'nano_3ncjetyr5436rdu94oxk8zuaqhpnr8dcff6odomf8wo7b8nw1owk3efh35pr';
    private const string BLOCK = 'A1B2C3D4E5F60718293A4B5C6D7E8F90A1B2C3D4E5F60718293A4B5C6D7E8F90';

    public static function req(): array
    {
        return [
            'scheme' => 'exact',
            'network' => NanoExact::NETWORK,
            'amount' => self::AMOUNT,
            'asset' => NanoExact::ASSET,
            'payTo' => self::PAY_TO,
            'maxTimeoutSeconds' => 60,
            'extra' => ['paymentFlow' => 'upfront'],
        ];
    }

    /** The buyer's account in the tests: any valid address that is not payTo. */
    public static function buyer(): string
    {
        return NanoExact::account(str_repeat("\x11", 32));
    }

    public static function payment(): array
    {
        $scheme = new NanoExact(
            static fn (string $payTo, string $raw, string $representative): array => ['hash' => strtolower(self::BLOCK), 'account' => self::buyer()],
            static fn (): string => hex2bin(self::ORDER_ID),
        );

        return $scheme->pay(['resource' => ['url' => 'https://example.com/a']], self::req());
    }

    public function testSupportsNanoMainnetOnly(): void
    {
        $scheme = new NanoExact();
        $this->assertTrue($scheme->supports('nano:mainnet'));
        $this->assertFalse($scheme->supports('nano:testnet'));
        $this->assertFalse($scheme->supports('eip155:8453'));
        $this->assertTrue(Schemes::find(NanoExact::NETWORK) instanceof NanoExact);
        $this->assertTrue(Schemes::find(NanoExact::NETWORK, [], SpendsOnce::class) instanceof NanoExact);
    }

    public function testAnAddressRoundTripsTheGenesisAccount(): void
    {
        $key = hex2bin(self::GENESIS_KEY);
        $this->assertSame(self::PAY_TO, NanoExact::account($key));
        $this->assertSame($key, NanoExact::publicKey(self::PAY_TO));
        $this->assertTrue((new NanoExact())->valid(self::PAY_TO));
    }

    public function testAWrongChecksumOrShapeIsNoAddress(): void
    {
        $scheme = new NanoExact();
        // Last character changed: the 5-byte checksum no longer matches the key.
        $this->assertFalse($scheme->valid(substr(self::PAY_TO, 0, -1).'4'));
        $this->assertFalse($scheme->valid(self::PAY_TO."\n"));
        $this->assertFalse($scheme->valid(strtoupper(self::PAY_TO)));
        $this->assertFalse($scheme->valid('nano_'.substr(self::PAY_TO, 6)));
        $this->assertFalse($scheme->valid(''));
    }

    public function testXrbIsTheSameAccountSpelledNano(): void
    {
        $scheme = new NanoExact();
        $xrb = 'xrb_'.substr(self::PAY_TO, 5);
        $this->assertTrue($scheme->valid($xrb));
        $this->assertSame(self::PAY_TO, $scheme->spell($xrb));
        $this->assertTrue($scheme->same($xrb, self::PAY_TO));
        $this->assertFalse($scheme->same(self::PAY_TO, self::buyer()));
        $this->assertTrue($scheme->same(self::BLOCK, strtolower(self::BLOCK)));
    }

    public function testTheRepresentativeIsTheBindingStandardsCommitment(): void
    {
        $this->assertSame(self::REPRESENTATIVE, NanoExact::representative(self::ORDER_ID, self::PAY_TO, self::AMOUNT));
        // Bound to all three: another order, payee or amount is another representative.
        $this->assertNotSame(self::REPRESENTATIVE, NanoExact::representative(str_repeat('ab', 32), self::PAY_TO, self::AMOUNT));
        $this->assertNotSame(self::REPRESENTATIVE, NanoExact::representative(self::ORDER_ID, self::buyer(), self::AMOUNT));
        $this->assertNotSame(self::REPRESENTATIVE, NanoExact::representative(self::ORDER_ID, self::PAY_TO, '1000000000000000000000000001'));
    }

    public function testPayAsksTheWalletForTheExactSendAndNamesTheBlock(): void
    {
        $calls = [];
        $scheme = new NanoExact(
            static function (string $payTo, string $raw, string $representative) use (&$calls): array {
                $calls[] = [$payTo, $raw, $representative];

                return ['hash' => strtolower(self::BLOCK), 'account' => 'xrb_'.substr(self::buyer(), 5)];
            },
            static fn (): string => hex2bin(self::ORDER_ID),
        );
        $payment = $scheme->pay(['resource' => ['url' => 'https://example.com/a']], self::req());

        $this->assertSame([[self::PAY_TO, self::AMOUNT, self::REPRESENTATIVE]], $calls);
        $this->assertSame(2, $payment['x402Version']);
        $this->assertSame(self::req(), $payment['accepted']);
        $this->assertSame(['block' => self::BLOCK, 'account' => self::buyer(), 'orderId' => self::ORDER_ID], $payment['payload']);
        $this->assertSame(null, $scheme->verify($payment, self::req()));
        $this->assertSame(self::buyer(), $scheme->payer($payment));
    }

    public function testPayingWithoutAWalletThrows(): void
    {
        $this->expectException(\LogicException::class);
        (new NanoExact())->pay([], self::req());
    }

    public function testPayRefusesAnOfferWithAMistypedPayTo(): void
    {
        $req = self::req();
        $req['payTo'] = substr(self::PAY_TO, 0, -1).'4';
        $scheme = new NanoExact(static fn (): array => ['hash' => self::BLOCK, 'account' => self::buyer()]);

        $this->expectException(\InvalidArgumentException::class);
        $scheme->pay([], $req);
    }

    public function testPayRefusesAWalletAnswerThatIsNoBlock(): void
    {
        $scheme = new NanoExact(static fn (): array => ['hash' => 'sent', 'account' => self::buyer()]);

        $this->expectException(\LogicException::class);
        $scheme->pay([], self::req());
    }

    public function testVerifyRefusesAnythingButTheOfferInOneSpelling(): void
    {
        $scheme = new NanoExact();
        $cases = [
            'invalid_payload' => [
                static fn (array $p): array => ['payload' => ['block' => strtolower(self::BLOCK)] + $p['payload']] + $p,
                static fn (array $p): array => ['payload' => ['block' => self::BLOCK."\n"] + $p['payload']] + $p,
                static fn (array $p): array => ['payload' => ['account' => 'xrb_'.substr(self::buyer(), 5)] + $p['payload']] + $p,
                static fn (array $p): array => ['payload' => ['orderId' => strtoupper(self::ORDER_ID)] + $p['payload']] + $p,
                static fn (array $p): array => ['payload' => $p['payload'] + ['memo' => 'x']] + $p,
                static fn (array $p): array => ['accepted' => ['amount' => '1'] + $p['accepted']] + $p,
                static fn (array $p): array => ['accepted' => ['payTo' => self::buyer()] + $p['accepted']] + $p,
                static fn (array $p): array => ['payload' => 'x'] + $p,
            ],
            'invalid_x402_version' => [static fn (array $p): array => ['x402Version' => 1] + $p],
            'invalid_scheme' => [static fn (array $p): array => ['accepted' => ['scheme' => 'upto'] + $p['accepted']] + $p],
            'invalid_network' => [static fn (array $p): array => ['accepted' => ['network' => 'eip155:8453'] + $p['accepted']] + $p],
        ];
        foreach ($cases as $reason => $tamperings) {
            foreach ($tamperings as $i => $tamper) {
                $this->assertSame($reason, $scheme->verify($tamper(self::payment()), self::req()), "$reason #$i");
            }
        }
    }

    public function testVerifyRefusesABadOffer(): void
    {
        $scheme = new NanoExact();
        $bad = [
            ['asset' => 'USDC'],
            ['amount' => '0'],
            ['amount' => '1.5'],
            ['amount' => str_repeat('9', 40)],
            ['payTo' => 'xrb_'.substr(self::PAY_TO, 5)],
            ['maxTimeoutSeconds' => NanoExact::MAX_TIMEOUT + 1],
            ['maxTimeoutSeconds' => '60'],
        ];
        foreach ($bad as $i => $change) {
            $req = $change + self::req();
            $payment = self::payment();
            $payment['accepted'] = $req;
            $this->assertSame('invalid_payment_requirements', $scheme->verify($payment, $req), "offer #$i");
        }
    }

    public function testOneBlockIsOnePaymentWhateverOrderIdComesWithIt(): void
    {
        $payment = self::payment();
        $other = $payment;
        $other['payload']['orderId'] = str_repeat('ab', 32);

        $this->assertSame([NanoExact::NETWORK, self::BLOCK], (new NanoExact())->spent($payment));
        $this->assertSame(Settlement::key($payment), Settlement::key($other));
    }

    public function testAnOfferIsInXnoUpfrontAndShortLived(): void
    {
        $scheme = new NanoExact();
        $this->assertSame(['paymentFlow' => 'upfront'], $scheme->offerExtra(NanoExact::NETWORK, 60, true));
        $this->expectException(\InvalidArgumentException::class);
        $scheme->offerExtra(NanoExact::NETWORK, 60, false);
    }

    public function testAnOfferOutlivingTheClaimIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new NanoExact())->offerExtra(NanoExact::NETWORK, NanoExact::MAX_TIMEOUT + 1, true);
    }
}
