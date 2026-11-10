<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\AddressForm;
use Quebracho\X402\Budget;
use Quebracho\X402\CasperExact;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Expires;
use Quebracho\X402\FileLedger;
use Quebracho\X402\LedgerCheck;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentLedger;
use Quebracho\X402\Scheme;
use Quebracho\X402\Schemes;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\SolanaExact;
use Quebracho\X402\SpendsOnce;
use Quebracho\X402\VetsOffers;

/**
 * The optional interfaces of a Scheme (SpendsOnce, Expires, AddressForm, VetsOffers): the core asks the
 * payment's scheme, and never what family a network belongs to. A chain family of your own plugs in by
 * implementing them.
 */
final class SchemeInterfacesTest extends TestCase
{
    private const PAY_TO = '0x000000000000000000000000000000000000dEaD';
    private const KEY = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    /** A chain family the library does not ship: its payments are ['id' => …, 'until' => …, 'memo' => …]. */
    private static function own(): Scheme
    {
        return new class implements Scheme, SpendsOnce, Expires, AddressForm {
            public function supports(string $network): bool
            {
                return str_starts_with($network, 'own:');
            }

            public function pay(array $required, array $req): array
            {
                return [];
            }

            public function verify(array $payment, array $req): ?string
            {
                return null;
            }

            public function payer(array $payment): ?string
            {
                return 'someone';
            }

            public function spent(array $payment): ?array
            {
                return isset($payment['payload']['id']) ? [$payment['accepted']['network'], (string) $payment['payload']['id']] : null;
            }

            public function expiresAt(array $payment): ?int
            {
                return $payment['payload']['until'] ?? null;
            }

            public function tooShortFor(array $payment, int $seconds): ?string
            {
                return $payment['payload']['until'] - time() < $seconds ? 'own_too_short' : null;
            }

            public function valid(string $address): bool
            {
                return str_starts_with($address, 'own1');
            }

            public function spell(string $address): string
            {
                return strtolower($address);
            }

            public function same(string $a, string $b): bool
            {
                return strtolower($a) === strtolower($b);
            }
        };
    }

    private static function payment(string $memo, int $until = 0): array
    {
        return ['accepted' => ['network' => 'own:1'], 'payload' => ['id' => 7, 'until' => $until, 'memo' => $memo]];
    }

    public function testTheKeyOfAPaymentIsWhatItsSchemeSaysTheChainSpendsOnce(): void
    {
        $schemes = [self::own()];

        self::assertSame(Settlement::key(self::payment('a'), $schemes), Settlement::key(self::payment('b'), $schemes), 'the memo is not what the chain spends');
        self::assertSame(hash('sha256', json_encode(['own:1', '7'])), Settlement::key(self::payment('a'), $schemes));
        self::assertNotSame(Settlement::key(self::payment('a')), Settlement::key(self::payment('b')), 'without the scheme: the whole payload');
        $unreadable = ['accepted' => ['network' => 'own:1'], 'payload' => ['memo' => 'x']];
        self::assertSame(hash('sha256', json_encode(['memo' => 'x'])), Settlement::key($unreadable, $schemes), 'a payload the scheme cannot read: the whole payload');
    }

    public function testTheBuiltInKeysAreWhatTheyWere(): void
    {
        $evm = ['accepted' => ['network' => 'eip155:84532'], 'payload' => ['authorization' => ['from' => '0xAbC', 'nonce' => '0xDEF'], 'signature' => '0x01']];
        self::assertSame(hash('sha256', json_encode(['eip155:84532', '0xabc', '0xdef'])), Settlement::key($evm));
        $casper = ['accepted' => ['network' => 'casper:casper-test'], 'payload' => ['authorization' => ['from' => '00AA', 'nonce' => 'BB']]];
        self::assertSame(hash('sha256', json_encode(['casper:casper-test', '00aa', 'bb'])), Settlement::key($casper));
        // A network of no scheme, and a NEAR payment that carries a stray EVM field: never keyed as EVM.
        $unknown = ['accepted' => ['network' => 'cosmos:hub'], 'payload' => ['authorization' => ['from' => 'a', 'nonce' => 'b']]];
        self::assertSame(hash('sha256', json_encode($unknown['payload'])), Settlement::key($unknown));
        $near = ['accepted' => ['network' => 'near:testnet'], 'payload' => ['authorization' => ['from' => 'a', 'nonce' => 'b']]];
        self::assertSame(hash('sha256', json_encode($near['payload'])), Settlement::key($near));
    }

    public function testAClaimLivesUntilThePaymentExpiresWhenItsSchemeCanTell(): void
    {
        $schemes = [self::own()];

        self::assertSame(30, Settlement::ttl(self::payment('a', time() + 30), [], $schemes));
        self::assertSame(Settlement::TTL, Settlement::ttl(self::payment('a', time() + 86400), [], $schemes), 'never longer than Settlement::TTL');
        self::assertSame(Settlement::TTL, Settlement::ttl(self::payment('a', time() + 30)), 'no scheme that can tell: Settlement::TTL');
        self::assertSame(Settlement::REFERENCED_TTL, Settlement::ttl(self::payment('a', time() + 30), ['status' => 'pending', 'receipt' => ['transaction' => '0xabc']], $schemes));
        self::assertSame(1, Settlement::ttlUntil(time() - 5));
        self::assertSame(Settlement::TTL, Settlement::ttlUntil(null));
    }

    public function testEveryBuiltInSchemeSaysWhatItCan(): void
    {
        self::assertInstanceOf(EvmExact::class, Schemes::find('eip155:1'));
        self::assertInstanceOf(SolanaExact::class, Schemes::find('solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1', as: VetsOffers::class));
        self::assertInstanceOf(CasperExact::class, Schemes::find('casper:casper', as: Expires::class));
        self::assertNull(Schemes::find('near:testnet', as: Expires::class), 'NEAR payments carry no expiry the seller can read');
        self::assertNull(Schemes::find('cosmos:hub'));
        $own = self::own();
        self::assertSame($own, Schemes::find('own:1', [$own]));
        // A caller's plain scheme for a family the library knows: the library's answers what it cannot.
        $plain = new class implements Scheme {
            public function supports(string $network): bool
            {
                return str_starts_with($network, 'eip155:');
            }

            public function pay(array $required, array $req): array
            {
                return [];
            }

            public function verify(array $payment, array $req): ?string
            {
                return null;
            }

            public function payer(array $payment): ?string
            {
                return null;
            }
        };
        self::assertSame($plain, Schemes::find('eip155:1', [$plain]));
        self::assertInstanceOf(EvmExact::class, Schemes::find('eip155:1', [$plain], SpendsOnce::class));
    }

    public function testAnOfferIsBuiltWithTheSpellingItsSchemeGives(): void
    {
        self::assertSame(self::PAY_TO, Networks::requirements('eip155:84532', '0.01', strtolower(self::PAY_TO))['payTo'], 'EIP-55');
        foreach (['', 'nope', '0x12'] as $bad) {
            try {
                Networks::requirements('eip155:84532', '0.01', $bad);
                self::fail("\"$bad\" is no address");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Not a payTo address', $e->getMessage());
            }
        }
    }

    public function testABuyerSignsOnlyWhatTheSchemeVets(): void
    {
        $budget = Budget::usd('eip155:84532', '0.05');
        $req = Networks::requirements('eip155:84532', '0.01', self::PAY_TO);

        self::assertTrue($budget->allows($req));
        self::assertFalse($budget->allows(['extra' => ['name' => 'Other Coin', 'version' => '2']] + $req), "another token's domain");
        self::assertFalse($budget->allows(['payTo' => substr(self::PAY_TO, 2)] + $req), 'payTo in another spelling');
        self::assertTrue($budget->allows(['asset' => strtolower($req['asset'])] + $req), 'the asset in any case, as the scheme compares it');
        self::assertTrue((new EvmExact())->signable($req, Networks::get('eip155:84532')));
    }

    public function testASchemeSaysWhenAPaymentIsTooShortForSlowWork(): void
    {
        $now = 1_000_000;
        $evm = new EvmExact(new Signer(self::KEY), static fn (): int => $now);
        $req = Networks::requirements('eip155:84532', '0.01', self::PAY_TO);
        $payment = $evm->pay(['resource' => ['url' => 'https://example.test/paid'], 'accepts' => [$req]], $req);

        self::assertSame($now + 60, $evm->expiresAt($payment));
        self::assertNull($evm->tooShortFor($payment, 60 - EvmExact::SLACK));
        self::assertSame('invalid_exact_evm_payload_authorization_valid_before', $evm->tooShortFor($payment, 60 - EvmExact::SLACK + 1));
        self::assertNull($evm->expiresAt(['payload' => []]));
    }

    public function testANetworkIsCheckedAgainstTheSchemesAtDeploy(): void
    {
        Networks::assertSupported('eip155:84532', [new EvmExact()]);
        try {
            Networks::assertSupported('eip155:999999', [new EvmExact()]);
            self::fail('unknown network');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('not a known network', $e->getMessage());
        }
        $this->expectExceptionMessage('supported by no registered scheme (registered: '.SolanaExact::class.')');
        Networks::assertSupported('eip155:84532', [new SolanaExact()]);
    }

    public function testALedgerIsCheckedAtDeploy(): void
    {
        $dir = sys_get_temp_dir().'/ledger-check-'.bin2hex(random_bytes(4));
        try {
            LedgerCheck::run(new FileLedger($dir));
            self::assertSame([], (new FileLedger($dir))->openKeys(10), 'the check leaves nothing behind');
        } finally {
            exec('rm -rf '.escapeshellarg($dir));
        }
        $deaf = $this->createStub(PaymentLedger::class);
        $deaf->method('claim')->willReturn(false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not take a claim');
        LedgerCheck::run($deaf);
    }
}
