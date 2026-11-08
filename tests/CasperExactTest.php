<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\CasperExact;
use Quebracho\X402\HttpFacilitator;
use Quebracho\X402\Networks;
use Quebracho\X402\Settlement;

/**
 * Pinned against @make-software/casper-x402 1.0.0 (the CSPR.cloud facilitator's
 * code): its client signed VECTOR from SEED at NOW with nonce 01..20, and its
 * facilitator's verify() returned isValid true for it.
 */
final class CasperExactTest extends TestCase
{
    private const NOW = 1_800_000_000;
    // RFC 8032 test 1 secret key.
    private const SEED = '9d61b19deffd5a60ba844af492ec2cc44449c5697b326919703bac031cae7f60';
    private const PUBLIC_KEY = '01d75a980182b10ab7d54bfed3c964073a0ee172f3daa62325af021a68f707511a';
    private const FROM = '00b6c0e5c9ee25f43f57e577b5821688b9ac164eb7c4c08a24d43d1806ac721342';
    private const NONCE = '0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20';
    private const DIGEST = '0x7aa9f949270ee05159b53b44b4d9eb8c9586a8cbd0b4dcf584b5280ac97d6ab9';
    private const SIGNATURE = '01f7dd7dbf2bd27a3629ab8ee9139de1d5060110a6a4e5d6dd2edb2938e214dfd5d2a05f59f24dd8614f5dea2752e34b8499a1ab09402874aec5d08ba3a6e22e09';

    protected function tearDown(): void
    {
        Networks::reset();
    }

    private static function req(): array
    {
        return [
            'scheme' => 'exact',
            'network' => CasperExact::TESTNET_NETWORK,
            'amount' => '1500000000',
            'asset' => '9824d60dc3a5c44a20b9fd260a412437933835b52fc683d8ae36e4ec2114843e',
            'payTo' => '00'.str_repeat('ab', 32),
            'maxTimeoutSeconds' => 60,
            'extra' => ['name' => 'Cep18x402', 'version' => '1'],
        ];
    }

    private static function scheme(int $now = self::NOW): CasperExact
    {
        return new CasperExact(hex2bin(self::SEED), fn () => $now, fn () => hex2bin(self::NONCE));
    }

    private static function payment(): array
    {
        return self::scheme()->pay(['resource' => ['url' => 'https://example.com/a']], self::req());
    }

    public function testSupportsBothCasperNetworksOnly(): void
    {
        $scheme = new CasperExact();
        $this->assertTrue($scheme->supports('casper:casper'));
        $this->assertTrue($scheme->supports('casper:casper-test'));
        $this->assertFalse($scheme->supports('casper:integration-test'));
        $this->assertFalse($scheme->supports('eip155:84532'));
    }

    public function testPayMatchesTheReferenceClientByteForByte(): void
    {
        $payment = self::payment();

        $this->assertSame(2, $payment['x402Version']);
        $this->assertSame(['url' => 'https://example.com/a'], $payment['resource']);
        $this->assertSame(self::req(), $payment['accepted']);
        $this->assertSame([
            'signature' => self::SIGNATURE,
            'publicKey' => self::PUBLIC_KEY,
            'authorization' => [
                'from' => self::FROM,
                'to' => '00'.str_repeat('ab', 32),
                'value' => '1500000000',
                'validAfter' => '1799999400',
                'validBefore' => '1800000060',
                'nonce' => self::NONCE,
            ],
        ], $payment['payload']);
    }

    public function testDigestMatchesCasperEip712(): void
    {
        $this->assertSame(self::DIGEST, CasperExact::digest(self::req(), self::payment()['payload']['authorization']));
    }

    public function testDigestBindsTheNetworkAsChainName(): void
    {
        $req = self::req();
        $req['network'] = CasperExact::MAINNET_NETWORK;
        $this->assertNotSame(self::DIGEST, CasperExact::digest($req, self::payment()['payload']['authorization']));
    }

    public function testAccountAddressIsBlake2bOfAlgorithmAndKey(): void
    {
        $this->assertSame(self::FROM, CasperExact::accountAddress(self::PUBLIC_KEY));
        // secp256k1 keys hash under their own algorithm name.
        $this->assertSame('00'.bin2hex(sodium_crypto_generichash("secp256k1\x00".str_repeat("\x02", 33), '', 32)), CasperExact::accountAddress('02'.str_repeat('02', 33)));
    }

    public function testAccountAddressRejectsAnUnknownAlgorithm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CasperExact::accountAddress('03'.str_repeat('00', 32));
    }

    public function testVerifyAcceptsTheReferencePayment(): void
    {
        $scheme = self::scheme();
        $this->assertNull($scheme->verify(self::payment(), self::req()));
        $this->assertSame(self::FROM, $scheme->payer(self::payment()));
    }

    public function testVerifyAcceptsAMainnetPayment(): void
    {
        $req = self::req();
        $req['network'] = CasperExact::MAINNET_NETWORK;
        $payment = self::scheme()->pay([], $req);

        $this->assertNull(self::scheme()->verify($payment, $req));
        $this->assertNotSame(self::SIGNATURE, $payment['payload']['signature']);
    }

    public static function tampered(): iterable
    {
        yield 'no authorization' => [fn (array $p) => ['payload' => ['signature' => $p['payload']['signature'], 'publicKey' => self::PUBLIC_KEY]] + $p, 'invalid_payload'];
        yield 'no public key' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['publicKey' => null]]), 'invalid_payload'];
        yield 'uppercase nonce' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['authorization' => ['nonce' => strtoupper(self::NONCE)]]]), 'invalid_payload'];
        yield 'short nonce' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['authorization' => ['nonce' => substr(self::NONCE, 2)]]]), 'invalid_payload'];
        yield 'secp256k1 key' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['publicKey' => '02'.str_repeat('02', 33)]]), 'invalid_payload'];
        yield 'signature without algorithm byte' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['signature' => substr(self::SIGNATURE, 2)]]), 'invalid_payload'];
        yield 'value with leading zero' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['authorization' => ['value' => '01500000000']]]), 'invalid_payload'];
        yield 'version 1' => [fn (array $p) => ['x402Version' => 1] + $p, 'invalid_x402_version'];
        yield 'other scheme' => [fn (array $p) => array_replace_recursive($p, ['accepted' => ['scheme' => 'upto']]), 'invalid_scheme'];
        yield 'other network' => [fn (array $p) => array_replace_recursive($p, ['accepted' => ['network' => 'casper:casper']]), 'invalid_network'];
        yield 'echoed other amount' => [fn (array $p) => array_replace_recursive($p, ['accepted' => ['amount' => '1']]), 'invalid_payload'];
        yield 'echoed other payTo' => [fn (array $p) => array_replace_recursive($p, ['accepted' => ['payTo' => '00'.str_repeat('cd', 32)]]), 'invalid_payload'];
        yield 'echoed other asset' => [fn (array $p) => array_replace_recursive($p, ['accepted' => ['asset' => str_repeat('11', 32)]]), 'invalid_payload'];
        yield 'other recipient' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['authorization' => ['to' => '00'.str_repeat('cd', 32)]]]), 'invalid_exact_casper_payload_recipient_mismatch'];
        yield 'other value' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['authorization' => ['value' => '1500000001']]]), 'invalid_exact_casper_payload_authorization_value_mismatch'];
        yield 'not yet valid' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['authorization' => ['validAfter' => (string) (self::NOW + 1)]]]), 'invalid_exact_casper_payload_authorization_valid_after'];
        yield 'under 6 s left' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['authorization' => ['validBefore' => (string) (self::NOW + 5)]]]), 'invalid_exact_casper_payload_authorization_valid_before'];
        yield 'window past maxTimeout' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['authorization' => ['validBefore' => (string) (self::NOW + 121)]]]), 'invalid_exact_casper_payload_authorization_valid_before'];
        yield 'other payer' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['authorization' => ['from' => '00'.str_repeat('ef', 32)]]]), 'invalid_exact_casper_payload_publickey_mismatch'];
        yield 'other nonce' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['authorization' => ['nonce' => str_repeat('00', 32)]]]), 'invalid_exact_casper_payload_signature'];
        yield 'flipped signature' => [fn (array $p) => array_replace_recursive($p, ['payload' => ['signature' => substr(self::SIGNATURE, 0, -2).'08']]), 'invalid_exact_casper_payload_signature'];
    }

    #[DataProvider('tampered')]
    public function testVerifyRejects(\Closure $tamper, string $reason): void
    {
        $this->assertSame($reason, self::scheme()->verify($tamper(self::payment()), self::req()));
    }

    public function testVerifyAcceptsTheLastSecondsAndTheMaxWindow(): void
    {
        // validBefore is NOW + 60: 6 s left at NOW + 54, and the +60 s skew allows NOW - 60.
        $this->assertNull(self::scheme(self::NOW + 54)->verify(self::payment(), self::req()));
        $this->assertSame('invalid_exact_casper_payload_authorization_valid_before', self::scheme(self::NOW + 55)->verify(self::payment(), self::req()));
        $this->assertNull(self::scheme(self::NOW - 60)->verify(self::payment(), self::req()));
        $this->assertSame('invalid_exact_casper_payload_authorization_valid_before', self::scheme(self::NOW - 61)->verify(self::payment(), self::req()));
    }

    public static function badOffers(): iterable
    {
        yield 'no token name' => [['extra' => ['name' => '', 'version' => '1']]];
        yield 'no token version' => [['extra' => ['name' => 'Cep18x402', 'version' => '']]];
        yield 'zero amount' => [['amount' => '0']];
        yield 'fractional amount' => [['amount' => '1.5']];
        yield 'prefixed asset' => [['asset' => 'hash-9824d60dc3a5c44a20b9fd260a412437933835b52fc683d8ae36e4ec2114843e']];
    }

    #[DataProvider('badOffers')]
    public function testVerifyRefusesABadOffer(array $change): void
    {
        $req = array_replace(self::req(), $change);
        $payment = self::payment();
        $payment['accepted'] = $req;
        $this->assertSame('invalid_payment_requirements', self::scheme()->verify($payment, $req));
    }

    public function testPayNeedsAKeyAndAValidOffer(): void
    {
        try {
            (new CasperExact())->pay([], self::req());
            $this->fail('paid without a key');
        } catch (\LogicException $e) {
            $this->assertSame('Paying needs a buyer secret key.', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        self::scheme()->pay([], array_replace(self::req(), ['payTo' => '02'.str_repeat('ab', 32)]));
    }

    public function testPayRefusesAnOfferWithoutADomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::scheme()->pay([], array_replace(self::req(), ['extra' => []]));
    }

    public function testPayAcceptsA64ByteSecretKey(): void
    {
        $kp = sodium_crypto_sign_seed_keypair(hex2bin(self::SEED));
        $scheme = new CasperExact(sodium_crypto_sign_secretkey($kp), fn () => self::NOW, fn () => hex2bin(self::NONCE));
        $this->assertSame(self::SIGNATURE, $scheme->pay([], self::req())['payload']['signature']);
    }

    public function testPayUsesAFreshNonceByDefault(): void
    {
        $scheme = new CasperExact(hex2bin(self::SEED), fn () => self::NOW);
        $a = $scheme->pay([], self::req());
        $this->assertNotSame($a['payload']['authorization']['nonce'], $scheme->pay([], self::req())['payload']['authorization']['nonce']);
        $this->assertNull($scheme->verify($a, self::req()));
    }

    public function testClaimKeyIsPayerAndNonce(): void
    {
        $payment = self::payment();
        $resigned = $payment;
        $resigned['payload']['signature'] = '01'.str_repeat('00', 64);
        $this->assertSame(Settlement::key($payment), Settlement::key($resigned));

        $other = self::scheme()->pay([], self::req());
        $other['payload']['authorization']['nonce'] = str_repeat('00', 32);
        $this->assertNotSame(Settlement::key($payment), Settlement::key($other));
    }

    public function testNetworksBuildsACasperOfferInMotes(): void
    {
        $network = Networks::addCasper(CasperExact::TESTNET_NETWORK, self::req()['asset'], 'Cep18x402', '1', label: 'Casper testnet');
        $req = Networks::requirements($network, '1.5', '00'.str_repeat('ab', 32));

        $this->assertSame(self::req(), $req);
        $this->assertSame('Casper testnet', Networks::get($network)['label']);
        $this->assertSame(9, Networks::get($network)['decimals']);
        $this->assertSame('1', Networks::atomic('0.000000001', CasperExact::DECIMALS));
    }

    public function testNetworksRefusesSubMotePrecisionAndForeignAddresses(): void
    {
        $network = Networks::addCasper(CasperExact::MAINNET_NETWORK, self::req()['asset'], 'Cep18x402', '1');
        try {
            Networks::requirements($network, '0.0000000001', '00'.str_repeat('ab', 32));
            $this->fail('truncated a sub-mote price');
        } catch (\InvalidArgumentException) {
        }
        $this->expectException(\InvalidArgumentException::class);
        Networks::requirements($network, '1', '0x'.str_repeat('ab', 20));
    }

    public static function badCasperNetworks(): iterable
    {
        yield 'not casper' => ['near:testnet', str_repeat('ab', 32), 'n', '1'];
        yield 'unknown chain' => ['casper:dev-net', str_repeat('ab', 32), 'n', '1'];
        yield 'uppercase hash' => ['casper:casper', str_repeat('AB', 32), 'n', '1'];
        yield 'no name' => ['casper:casper', str_repeat('ab', 32), '', '1'];
        yield 'no version' => ['casper:casper', str_repeat('ab', 32), 'n', ''];
    }

    #[DataProvider('badCasperNetworks')]
    public function testAddCasperRefuses(string $network, string $hash, string $name, string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Networks::addCasper($network, $hash, $name, $version);
    }

    public function testCsprCloudFacilitatorUrl(): void
    {
        $this->assertSame('https://x402-facilitator.cspr.cloud', HttpFacilitator::CSPR_CLOUD);
    }
}
