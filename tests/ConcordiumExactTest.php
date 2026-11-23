<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\Concordium;
use Quebracho\X402\ConcordiumExact;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\X402Middleware;

final class ConcordiumExactTest extends TestCase
{
    private const int NOW = 1700000000;
    private const string SENDER = '35G83kEaPTNk9DkdkbXtGyRiBvZpxAbqgYSzdLMfJLkKwHU7TE';
    private const string PAY_TO = '3Cn4s3SJMEXMTroMva4pEM5WGER1CVKtj1nk6ypKwg7AJV6JqA';
    private const string SPONSOR = '3LJ1gLe2K1fxnVr66YbkBijJLYGBSp3wmV8VadGzb1TzcXNtAV';
    // Throwaway seed: these tests never touch a real key.
    private const string SEED = '0101010101010101010101010101010101010101010101010101010101010101';

    private function req(): array
    {
        return [
            'scheme' => 'exact', 'network' => ConcordiumExact::TESTNET_NETWORK, 'amount' => '1000000', 'asset' => 'CCD',
            'payTo' => self::PAY_TO, 'maxTimeoutSeconds' => 60, 'extra' => ['feePayer' => self::SPONSOR],
        ];
    }

    private function scheme(): ConcordiumExact
    {
        return new ConcordiumExact(['address' => self::SENDER, 'signKey' => self::SEED], fn () => 42, fn () => self::NOW);
    }

    private function payment(): array
    {
        return $this->scheme()->pay([], $this->req());
    }

    public function testSignHashMatchesTheSpikeWhoseTransactionFinalized(): void
    {
        // Same inputs through the spike's formula (kalibang/spike/step2-send.php): 635 NRG, as on testnet.
        $tx = $this->payment()['payload']['signedTransaction'];
        $tx['header']['expiry'] = 1700000300;
        $this->assertSame('458d433db94f3007c7e31a63f1e1f092ddf3eb438489536d0a3ec5f92eb815b8', bin2hex(Concordium::signHash($tx)));
    }

    public function testAddressRoundTrip(): void
    {
        $this->assertSame(str_repeat("\x11", 32), Concordium::decodeAddress(self::SENDER));
        $this->assertSame(self::SPONSOR, Concordium::encodeAddress(str_repeat("\x33", 32)));
        $this->assertTrue(Concordium::isAddress(self::PAY_TO));
        $this->assertFalse(Concordium::isAddress('nope'));
    }

    public function testAHonestPaymentVerifiesAndIsSignedByTheBuyer(): void
    {
        $payment = $this->payment();
        $this->assertNull($this->scheme()->verify($payment, $this->req()));
        $this->assertSame(self::SENDER, $this->scheme()->payer($payment));
        $tx = $payment['payload']['signedTransaction'];
        $public = bin2hex(sodium_crypto_sign_publickey_from_secretkey(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin(self::SEED)))));
        $this->assertTrue(Concordium::signedBy($tx, $tx['signatures']['sender']['0']['0'], $public));
        $tx['payload']['amount'] = '999999';
        $this->assertFalse(Concordium::signedBy($tx, $tx['signatures']['sender']['0']['0'], $public));
    }

    #[DataProvider('tampered')]
    public function testRefusesWhatTheSponsorMustNotPayFor(callable $tamper, string $reason): void
    {
        $payment = $this->payment();
        $req = $this->req();
        $tamper($payment, $req);
        $this->assertSame($reason, $this->scheme()->verify($payment, $req));
    }

    public static function tampered(): iterable
    {
        yield 'other sponsor' => [function (&$p) { $p['payload']['signedTransaction']['header']['sponsor']['address'] = self::PAY_TO; }, 'invalid_exact_concordium_payload_sponsor_mismatch'];
        yield 'other recipient' => [function (&$p) { $p['payload']['signedTransaction']['payload']['toAddress'] = self::SENDER; }, 'invalid_exact_concordium_payload_recipient_mismatch'];
        yield 'non-canonical sender' => [function (&$p) { $p['payload']['signedTransaction']['header']['sender'] = '1'.self::SENDER; }, 'invalid_payload'];
        yield 'less' => [function (&$p) { $p['payload']['signedTransaction']['payload']['amount'] = '999999'; }, 'invalid_exact_concordium_payload_amount_mismatch'];
        yield 'more' => [function (&$p) { $p['payload']['signedTransaction']['payload']['amount'] = '1000001'; }, 'invalid_exact_concordium_payload_amount_mismatch'];
        yield 'token update' => [function (&$p) { $p['payload']['signedTransaction']['payload']['type'] = 'tokenUpdate'; }, 'invalid_exact_concordium_payload_type'];
        yield 'extra operation' => [function (&$p) { $p['payload']['signedTransaction']['payload']['operations'] = '00'; }, 'invalid_exact_concordium_payload_type'];
        yield 'version 0' => [function (&$p) { $p['payload']['signedTransaction']['version'] = 0; }, 'invalid_exact_concordium_version'];
        yield 'sender is the sponsor' => [function (&$p) { $p['payload']['signedTransaction']['header']['sender'] = self::SPONSOR; }, 'invalid_exact_concordium_payload_sponsor_is_party'];
        yield 'pays the sponsor' => [function (&$p, &$r) {
            $r['payTo'] = self::SPONSOR;
            $p['accepted']['payTo'] = self::SPONSOR;
            $p['payload']['signedTransaction']['payload']['toAddress'] = self::SPONSOR;
        }, 'invalid_exact_concordium_payload_sponsor_is_party'];
        yield 'fat energy' => [function (&$p) { $p['payload']['signedTransaction']['header']['executionEnergyAmount'] = 1000000; }, 'invalid_exact_concordium_payload_energy'];
        yield 'sponsor slot filled' => [function (&$p) { $p['payload']['signedTransaction']['signatures']['sponsor'] = ['0' => ['0' => str_repeat('a', 128)]]; }, 'invalid_exact_concordium_payload_energy'];
        yield 'expired' => [function (&$p) { $p['payload']['signedTransaction']['header']['expiry'] = self::NOW + 1; }, 'invalid_exact_concordium_payload_expiry'];
        yield 'too far' => [function (&$p) { $p['payload']['signedTransaction']['header']['expiry'] = self::NOW + 61; }, 'invalid_exact_concordium_payload_expiry'];
        yield 'no signature' => [function (&$p) { $p['payload']['signedTransaction']['signatures']['sender'] = []; }, 'invalid_payload'];
        yield 'short signature' => [function (&$p) { $p['payload']['signedTransaction']['signatures']['sender']['0']['0'] = 'ab'; }, 'invalid_exact_concordium_payload_signature'];
        yield 'bad address' => [function (&$p) { $p['payload']['signedTransaction']['header']['sender'] = 'nope'; }, 'invalid_payload'];
        yield 'wrong network' => [function (&$p) { $p['accepted']['network'] = ConcordiumExact::MAINNET_NETWORK; }, 'invalid_network'];
        yield 'PLT asked' => [function (&$p, &$r) { $r['asset'] = 'EURR'; }, 'unsupported_asset'];
        yield 'bad fee payer' => [function (&$p, &$r) { $r['extra']['feePayer'] = 'nope'; }, 'invalid_payload'];
    }

    public function testSupportsOnlyConcordium(): void
    {
        $this->assertTrue($this->scheme()->supports(ConcordiumExact::TESTNET_NETWORK));
        $this->assertTrue($this->scheme()->supports(ConcordiumExact::MAINNET_NETWORK));
        $this->assertFalse($this->scheme()->supports('ccd:other'));
        $this->assertFalse($this->scheme()->supports('eip155:84532'));
    }

    public function testSpendsOncePerNetworkPayerAndNonce(): void
    {
        $this->assertSame([ConcordiumExact::TESTNET_NETWORK, self::SENDER, '42'], $this->scheme()->spent($this->payment()));
    }

    public function testExpiryIsReadAndShortWorkIsRefused(): void
    {
        $payment = $this->payment();
        $this->assertSame(self::NOW + 60, $this->scheme()->expiresAt($payment));
        $this->assertNull($this->scheme()->tooShortFor($payment, 10));
        $this->assertSame('invalid_exact_concordium_payload_expiry', $this->scheme()->tooShortFor($payment, 60));
    }

    public function testAddressFormIsBase58Check(): void
    {
        $scheme = $this->scheme();
        $this->assertTrue($scheme->valid(self::PAY_TO));
        $this->assertFalse($scheme->valid('nope'));
        $this->assertSame(self::PAY_TO, $scheme->spell(self::PAY_TO));
        $this->assertTrue($scheme->same(self::PAY_TO, self::PAY_TO));
        $this->assertFalse($scheme->same(self::PAY_TO, self::SPONSOR));
    }

    public function testVetsTheOfferBeforeSigning(): void
    {
        $scheme = $this->scheme();
        $this->assertTrue($scheme->signable($this->req(), []));

        $noFeePayer = $this->req();
        unset($noFeePayer['extra']['feePayer']);
        $this->assertFalse($scheme->signable($noFeePayer, []));

        $sponsorIsPayee = $this->req();
        $sponsorIsPayee['extra']['feePayer'] = $sponsorIsPayee['payTo'];
        $this->assertFalse($scheme->signable($sponsorIsPayee, []));

        $buyerIsSponsor = $this->req();
        $buyerIsSponsor['extra']['feePayer'] = self::SENDER;
        $this->assertFalse($scheme->signable($buyerIsSponsor, []));

        $usd = $this->req();
        $usd['asset'] = 'USDC';
        $this->assertFalse($scheme->signable($usd, []));

        $zero = $this->req();
        $zero['amount'] = '0';
        $this->assertFalse($scheme->signable($zero, []));
    }

    public function testOfferExtraNamesTheFacilitator(): void
    {
        $scheme = new ConcordiumExact(feePayer: self::SPONSOR);
        $this->assertSame(['feePayer' => self::SPONSOR, 'paymentFlow' => 'upfront'], $scheme->offerExtra(ConcordiumExact::TESTNET_NETWORK, 60, true));

        $this->expectException(\InvalidArgumentException::class);
        (new ConcordiumExact())->offerExtra(ConcordiumExact::TESTNET_NETWORK, 60, true);
    }

    public function testOfferExtraRefusesADollarPrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ConcordiumExact(feePayer: self::SPONSOR))->offerExtra(ConcordiumExact::TESTNET_NETWORK, 60, false);
    }

    public function testAnOfferBuiltInTokenPaysAndVerifies(): void
    {
        $seller = new ConcordiumExact(clock: fn () => self::NOW, feePayer: self::SPONSOR);
        $req = Networks::requirementsInToken(ConcordiumExact::TESTNET_NETWORK, '0.01', self::PAY_TO, 60, [$seller]);
        $this->assertSame('10000', $req['amount']);
        $this->assertSame('CCD', $req['asset']);
        $this->assertSame(self::SPONSOR, $req['extra']['feePayer']);

        $payment = $this->scheme()->pay([], $req);
        $this->assertNull($seller->verify($payment, $req));
    }

    public function testAnUpfrontOfferDoesNotRunTheHandlerWhenTheSettleFails(): void
    {
        $seller = new ConcordiumExact(clock: fn () => self::NOW, feePayer: self::SPONSOR);
        $req = Networks::requirementsInToken(ConcordiumExact::TESTNET_NETWORK, '0.01', self::PAY_TO, 60, [$seller]);
        $payment = $this->scheme()->pay([], $req);
        $runs = 0;
        $handler = new class($runs) implements RequestHandlerInterface {
            public function __construct(private int &$runs)
            {
            }

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): ResponseInterface
            {
                ++$this->runs;

                return new Response(200, [], 'paid');
            }
        };
        $f = new Psr17Factory();
        $middleware = new X402Middleware(
            [$seller],
            static fn (): array => [$req],
            static fn (string $key): bool => true,
            $f,
            $f,
            static fn (array $payment, array $req): array => ['success' => false, 'errorReason' => 'insufficient_funds', 'transaction' => '', 'network' => $req['network']],
        );
        $response = $middleware->process(new ServerRequest('GET', 'https://seller.test/paid', [PaymentHeaders::SIGNATURE => PaymentHeaders::encode($payment)]), $handler);

        $this->assertSame([402, 0], [$response->getStatusCode(), $runs]);
    }
}
