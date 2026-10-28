<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\NearExact;

final class NearExactTest extends TestCase
{
    private const CURRENT_BLOCK_HEIGHT = 100_000_000;
    private const SENDER_ID = 'buyer.testnet';
    private const RECEIVER_ID = 'seller.testnet';
    private const NONCE = 1_000_001;
    private const MAX_BLOCK_HEIGHT = self::CURRENT_BLOCK_HEIGHT + 60;

    private static function req(): array
    {
        return [
            'scheme' => 'exact',
            'network' => NearExact::TESTNET_NETWORK,
            'asset' => NearExact::TESTNET_USDC,
            'amount' => '10000',
            'payTo' => self::RECEIVER_ID,
            'maxTimeoutSeconds' => 60,
        ];
    }

    private static function keypair(): array
    {
        // Deterministic throwaway 32-byte seed for Ed25519 key generation
        $seed = str_repeat("\x42", 32);
        $kp = sodium_crypto_sign_seed_keypair($seed);
        return [
            'keypair' => $kp,
            'secret' => sodium_crypto_sign_secretkey($kp),
            'public' => sodium_crypto_sign_publickey($kp),
        ];
    }

    private static function payment(?string $network = NULL): array
    {
        $kp = self::keypair();
        $scheme = new NearExact(
            secretKey: $kp['keypair'],
            senderId: self::SENDER_ID,
            nonce: self::NONCE,
            clock: fn() => self::CURRENT_BLOCK_HEIGHT
        );

        $req = self::req();
        if ($network !== NULL) {
            $req['network'] = $network;
        }

        return $scheme->pay(
            ['x402Version' => 2, 'resource' => ['url' => 'https://example.test/mcp'], 'accepts' => [$req]],
            $req
        );
    }

    public function testNep366DiscriminantConstant(): void
    {
        self::assertSame((1 << 30) + 366, NearExact::NEP_366_DISCRIMINANT);
    }

    public function testSupportsNearTestnetOnly(): void
    {
        $scheme = new NearExact();
        self::assertTrue($scheme->supports('near:testnet'));
        self::assertFalse($scheme->supports('near:mainnet'));
        self::assertFalse($scheme->supports('eip155:84532'));
        self::assertFalse($scheme->supports('solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp'));
    }

    public function testAcceptsItsOwnPayment(): void
    {
        $scheme = new NearExact(clock: fn() => self::CURRENT_BLOCK_HEIGHT);
        self::assertNull($scheme->verify(self::payment(), self::req()));
    }

    /**
     * Test known-answer test vector from nearcore delegate.rs
     * Hex string represents serialized DelegateAction from nearcore tests:
     * sender_id="aaa", receiver_id="bbb", actions=[CreateAccount], nonce=1, max_block_height=2, pubkey=ED25519(32x00)
     * Citation: https://raw.githubusercontent.com/near/nearcore/master/core/primitives/src/action/delegate.rs
     */
    public function testKnownAnswerVectorFromNearcoreHex(): void
    {
        $delegateActionHex =
            '03000000616161' . // sender_id: "aaa" (len=3)
            '03000000626262' . // receiver_id: "bbb" (len=3)
            '01000000' .       // actions vec len=1
            '00' .             // CreateAccountAction discriminant = 0
            '0100000000000000' . // nonce: 1 (u64 LE)
            '0200000000000000' . // max_block_height: 2 (u64 LE)
            '00' . str_repeat('00', 32); // public_key: ED25519(0x00...00)

        // Decode DelegateAction with custom decoder
        $decoded = NearExact::decodeSignedDelegateAction($delegateActionHex . "\x00" . str_repeat("\x00", 64));
        self::assertNull($decoded); // CreateAccount action type is 0, decodeSignedDelegateAction expects FunctionCall (type 2)

        // Test encoding of DelegateAction with FunctionCall and verify exact byte representation against spec
        $encoded = NearExact::encodeDelegateAction(
            'aaa',
            'bbb',
            'ft_transfer',
            '{"receiver_id":"ccc","amount":"10000"}',
            30_000_000_000_000,
            1,
            1,
            2,
            str_repeat("\x00", 32)
        );

        $expectedHex =
            '03000000616161' . // sender_id: "aaa"
            '03000000626262' . // receiver_id: "bbb"
            '01000000' .       // actions len: 1
            '02' .             // Action::FunctionCall (tag = 2)
            '0b00000066745f7472616e73666572' . // method_name: "ft_transfer" (len=11)
            '260000007b2272656365697665725f6964223a22636363222c22616d6f756e74223a223130303030227d' . // args: '{"receiver_id":"ccc","amount":"10000"}' (len=38 with unescaped JSON)
            '00e057eb481b0000' . // gas: 30_000_000_000_000 (u64 LE: 0x00001b48eb57e000)
            '01000000000000000000000000000000' . // deposit: 1 yoctoNEAR (u128 LE)
            '0100000000000000' . // nonce: 1 (u64 LE)
            '0200000000000000' . // max_block_height: 2 (u64 LE)
            '00' . str_repeat('00', 32); // pubkey: ED25519(32x00)

        self::assertSame($expectedHex, bin2hex($encoded));

        $decodedFunc = NearExact::decodeSignedDelegateAction($encoded . "\x00" . str_repeat("\x00", 64));
        self::assertNotNull($decodedFunc);
        self::assertSame('aaa', $decodedFunc['sender_id']);
        self::assertSame('bbb', $decodedFunc['receiver_id']);
        self::assertSame('ft_transfer', $decodedFunc['method_name']);
        self::assertSame(1, $decodedFunc['nonce']);
        self::assertSame(2, $decodedFunc['max_block_height']);
    }

    public static function tampered(): iterable
    {
        yield 'amount' => [
            fn(array $p) => ['payload' => ['signedDelegateAction' => base64_encode(NearExact::encodeDelegateAction(
                self::SENDER_ID,
                NearExact::TESTNET_USDC,
                'ft_transfer',
                '{"receiver_id":"seller.testnet","amount":"20000"}',
                30_000_000_000_000,
                1,
                self::NONCE,
                self::MAX_BLOCK_HEIGHT,
                sodium_crypto_sign_publickey(self::keypair()['keypair'])
            ) . "\x00" . str_repeat("\x00", 64))]],
            'invalid_amount'
        ];
        yield 'recipient' => [
            fn(array $p) => ['payload' => ['signedDelegateAction' => base64_encode(NearExact::encodeDelegateAction(
                self::SENDER_ID,
                NearExact::TESTNET_USDC,
                'ft_transfer',
                '{"receiver_id":"attacker.testnet","amount":"10000"}',
                30_000_000_000_000,
                1,
                self::NONCE,
                self::MAX_BLOCK_HEIGHT,
                sodium_crypto_sign_publickey(self::keypair()['keypair'])
            ) . "\x00" . str_repeat("\x00", 64))]],
            'invalid_recipient'
        ];
        yield 'deposit' => [
            fn(array $p) => ['payload' => ['signedDelegateAction' => base64_encode(NearExact::encodeDelegateAction(
                self::SENDER_ID,
                NearExact::TESTNET_USDC,
                'ft_transfer',
                '{"receiver_id":"seller.testnet","amount":"10000"}',
                30_000_000_000_000,
                2,
                self::NONCE,
                self::MAX_BLOCK_HEIGHT,
                sodium_crypto_sign_publickey(self::keypair()['keypair'])
            ) . "\x00" . str_repeat("\x00", 64))]],
            'invalid_payload'
        ];
        yield 'network' => [
            fn(array $p) => ['accepted' => ['network' => 'near:mainnet']],
            'unsupported_scheme'
        ];
        yield 'garbage_payload' => [
            fn(array $p) => ['payload' => ['signedDelegateAction' => 'aW52YWxpZF9kZWxlZ2F0ZV9hY3Rpb25fZ2FyYmFnZQ==']],
            'invalid_payload'
        ];
    }

    #[DataProvider('tampered')]
    public function testRejectsTamperedPayments(\Closure $patch, string $reason): void
    {
        $payment = array_replace_recursive(self::payment(), $patch(self::payment()));
        $scheme = new NearExact(clock: fn() => self::CURRENT_BLOCK_HEIGHT);
        self::assertSame($reason, $scheme->verify($payment, self::req()));
    }

    public function testRejectsExpiredPayments(): void
    {
        // Verification at block height past max_block_height
        $scheme = new NearExact(clock: fn() => self::MAX_BLOCK_HEIGHT + 1);
        self::assertSame('expired', $scheme->verify(self::payment(), self::req()));
    }

    public function testRejectsTamperedSignature(): void
    {
        $payment = self::payment();
        $raw = base64_decode($payment['payload']['signedDelegateAction']);
        // Tamper signature byte near the end
        $tamperedRaw = substr($raw, 0, -5) . "\xFF\xFF\xFF\xFF\xFF";
        $payment['payload']['signedDelegateAction'] = base64_encode($tamperedRaw);

        $scheme = new NearExact(clock: fn() => self::CURRENT_BLOCK_HEIGHT);
        self::assertSame('invalid_signature', $scheme->verify($payment, self::req()));
    }

    public function testPaysWithA64ByteSecretKey(): void
    {
        $scheme = new NearExact(
            secretKey: self::keypair()['secret'],
            senderId: self::SENDER_ID,
            nonce: self::NONCE,
            clock: fn() => self::CURRENT_BLOCK_HEIGHT,
        );
        $payment = $scheme->pay(['x402Version' => 2, 'accepts' => [self::req()]], self::req());
        $verifier = new NearExact(clock: fn() => self::CURRENT_BLOCK_HEIGHT);
        self::assertNull($verifier->verify($payment, self::req()));
    }

    public function testPayloadIsTheSpecShape(): void
    {
        self::assertSame(['signedDelegateAction'], array_keys(self::payment()['payload']));
    }

    public function testRejectsAWindowLongerThanTheTimeout(): void
    {
        // Signed 60 blocks ahead, but the seller now allows only 10 seconds.
        $req = ['maxTimeoutSeconds' => 10] + self::req();
        $scheme = new NearExact(clock: fn() => self::CURRENT_BLOCK_HEIGHT);
        self::assertSame('timeout_window_exceeds_maxTimeout', $scheme->verify(self::payment(), $req));
    }

    public function testThePayerIsUnprovenWithoutAKeyOwnerCheck(): void
    {
        self::assertNull((new NearExact())->payer(self::payment()));
    }

    public function testThePayerIsTheSenderOnlyWhenTheKeyIsTheirs(): void
    {
        $public = self::keypair()['public'];
        $owns = new NearExact(keyOwner: fn(string $account, string $key) => $account === self::SENDER_ID && $key === $public);
        $foreign = new NearExact(keyOwner: fn() => false);
        $down = new NearExact(keyOwner: fn() => throw new \RuntimeException('RPC down'));

        self::assertSame(self::SENDER_ID, $owns->payer(self::payment()));
        self::assertNull($foreign->payer(self::payment()));
        self::assertNull($down->payer(self::payment()));
    }
}
