<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\Concordium;
use Quebracho\X402\ConcordiumExact;
use Quebracho\X402\ConcordiumFacilitator;
use Quebracho\X402\ConcordiumNode;
use Quebracho\X402\FacilitatorException;

final class ConcordiumFacilitatorTest extends TestCase
{
    private const int NOW = 1700000000;
    private const string SENDER = '35G83kEaPTNk9DkdkbXtGyRiBvZpxAbqgYSzdLMfJLkKwHU7TE';
    private const string PAY_TO = '3Cn4s3SJMEXMTroMva4pEM5WGER1CVKtj1nk6ypKwg7AJV6JqA';
    private const string SPONSOR = '3LJ1gLe2K1fxnVr66YbkBijJLYGBSp3wmV8VadGzb1TzcXNtAV';
    private const string SEED = '0101010101010101010101010101010101010101010101010101010101010101';
    private const string SPONSOR_SEED = '0202020202020202020202020202020202020202020202020202020202020202';
    public const string HASH = 'abababababababababababababababababababababababababababababababab';

    private function req(): array
    {
        return [
            'scheme' => 'exact', 'network' => ConcordiumExact::TESTNET_NETWORK, 'amount' => '1000000', 'asset' => 'CCD',
            'payTo' => self::PAY_TO, 'maxTimeoutSeconds' => 60, 'extra' => ['feePayer' => self::SPONSOR],
        ];
    }

    private function payment(): array
    {
        return (new ConcordiumExact(['address' => self::SENDER, 'signKey' => self::SEED], fn () => 42, fn () => self::NOW))->pay([], $this->req());
    }

    private function node(string $senderPublicKey = ''): FakeConcordiumNode
    {
        $public = '' === $senderPublicKey
            ? bin2hex(sodium_crypto_sign_publickey_from_secretkey(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin(self::SEED)))))
            : $senderPublicKey;
        $node = new FakeConcordiumNode(self::SENDER);
        $node->info = [
            'amount' => 10_000_000,
            'available' => 10_000_000,
            'threshold' => 1,
            'credentials' => [0 => ['threshold' => 1, 'keys' => [0 => $public]]],
        ];
        $node->status = ['finalized' => true, 'success' => true, 'amount' => 1_000_000, 'receiver' => self::PAY_TO, 'transaction' => self::HASH];

        return $node;
    }

    private function facilitator(?FakeConcordiumNode $node = null, string $sponsor = self::SPONSOR, string $key = self::SPONSOR_SEED): ConcordiumFacilitator
    {
        return new ConcordiumFacilitator($node ?? $this->node(), $sponsor, $key, ConcordiumExact::TESTNET_NETWORK, fn () => self::NOW, 0, 1);
    }

    public function testAVerifiedPaymentNamesItsPayer(): void
    {
        $this->assertSame(['isValid' => true, 'payer' => self::SENDER], $this->facilitator()->verify($this->payment(), $this->req()));
    }

    public function testTheSenderSignatureIsCheckedAgainstTheOnChainKeys(): void
    {
        $payment = $this->payment();
        $payment['payload']['signedTransaction']['signatures']['sender']['0']['0'] = str_repeat('a', 128);

        $answer = $this->facilitator()->verify($payment, $this->req());
        $this->assertFalse($answer['isValid']);
        $this->assertSame('invalid_exact_concordium_signature', $answer['invalidReason']);
    }

    public function testAKeyThatIsNotTheAccountsIsRefused(): void
    {
        $node = $this->node(bin2hex(sodium_crypto_sign_publickey_from_secretkey(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin(self::SPONSOR_SEED))))));

        $answer = $this->facilitator($node)->verify($this->payment(), $this->req());
        $this->assertFalse($answer['isValid']);
        $this->assertSame('invalid_exact_concordium_signature', $answer['invalidReason']);
    }

    public function testAStaleNonceIsRefused(): void
    {
        $node = $this->node();
        $node->nonce = 43;

        $answer = $this->facilitator($node)->verify($this->payment(), $this->req());
        $this->assertFalse($answer['isValid']);
        $this->assertSame('invalid_exact_concordium_nonce', $answer['invalidReason']);
    }

    public function testABalanceBelowTheTransferIsRefused(): void
    {
        $node = $this->node();
        $node->info['available'] = 999_999;

        $answer = $this->facilitator($node)->verify($this->payment(), $this->req());
        $this->assertFalse($answer['isValid']);
        $this->assertSame('invalid_exact_concordium_balance', $answer['invalidReason']);
    }

    public function testAnAccountThatDoesNotExistIsRefused(): void
    {
        $node = $this->node();
        $node->info = null;

        $answer = $this->facilitator($node)->verify($this->payment(), $this->req());
        $this->assertFalse($answer['isValid']);
        $this->assertSame('invalid_exact_concordium_sender_unknown', $answer['invalidReason']);
    }

    public function testAnotherSponsorIsNotSponsored(): void
    {
        $answer = $this->facilitator(null, self::PAY_TO)->verify($this->payment(), $this->req());
        $this->assertFalse($answer['isValid']);
        $this->assertSame('invalid_exact_concordium_sponsor_mismatch', $answer['invalidReason']);
    }

    public function testAnOfflineRefusalIsReturnedAsIs(): void
    {
        $payment = $this->payment();
        $payment['payload']['signedTransaction']['payload']['amount'] = '999999';

        $answer = $this->facilitator()->verify($payment, $this->req());
        $this->assertFalse($answer['isValid']);
        $this->assertSame('invalid_exact_concordium_payload_amount_mismatch', $answer['invalidReason']);
    }

    public function testSettleSignsAsSponsorAndReturnsTheFinalizedHash(): void
    {
        $node = $this->node();
        $result = $this->facilitator($node)->settle($this->payment(), $this->req());

        $this->assertSame(['success' => true, 'transaction' => self::HASH, 'network' => ConcordiumExact::TESTNET_NETWORK, 'payer' => self::SENDER], $result);
        $this->assertCount(1, $node->sent);
        $sent = $node->sent[0];
        $this->assertArrayHasKey('sponsor', $sent['signatures']);
        $public = hex2bin(bin2hex(sodium_crypto_sign_publickey_from_secretkey(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin(self::SPONSOR_SEED))))));
        $this->assertTrue(Concordium::signedBy($sent, $sent['signatures']['sponsor']['0']['0'], bin2hex($public)));
    }

    public function testSettleWithoutFinalizationIsPendingWithTheHash(): void
    {
        $node = $this->node();
        $node->status = ['finalized' => false, 'success' => false, 'amount' => null, 'receiver' => null, 'transaction' => self::HASH];

        $result = $this->facilitator($node)->settle($this->payment(), $this->req());
        $this->assertFalse($result['success']);
        $this->assertSame('settlement_pending', $result['errorReason']);
        $this->assertSame(self::HASH, $result['transaction']);
    }

    public function testSettleReportsARejectedTransaction(): void
    {
        $node = $this->node();
        $node->status = ['finalized' => true, 'success' => false, 'amount' => null, 'receiver' => null, 'transaction' => self::HASH];

        $result = $this->facilitator($node)->settle($this->payment(), $this->req());
        $this->assertFalse($result['success']);
        $this->assertSame('settlement_failed', $result['errorReason']);
        $this->assertSame(self::HASH, $result['transaction']);
    }

    public function testSettleRefusesATransferThatIsNotTheOffer(): void
    {
        $node = $this->node();
        $node->status = ['finalized' => true, 'success' => true, 'amount' => 999_999, 'receiver' => self::PAY_TO, 'transaction' => self::HASH];

        $result = $this->facilitator($node)->settle($this->payment(), $this->req());
        $this->assertFalse($result['success']);
        $this->assertSame('settlement_mismatch', $result['errorReason']);
    }

    public function testSettleReturnsTheVerifyReasonWithoutSending(): void
    {
        $node = $this->node();
        $node->nonce = 43;

        $result = $this->facilitator($node)->settle($this->payment(), $this->req());
        $this->assertFalse($result['success']);
        $this->assertSame('invalid_exact_concordium_nonce', $result['errorReason']);
        $this->assertSame('', $result['transaction']);
        $this->assertSame([], $node->sent);
    }

    public function testTheConstructorRefusesABadSponsorOrKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConcordiumFacilitator($this->node(), 'nope', self::SPONSOR_SEED, ConcordiumExact::TESTNET_NETWORK);
    }

    public function testTheConstructorRefusesABadKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConcordiumFacilitator($this->node(), self::SPONSOR, 'nope', ConcordiumExact::TESTNET_NETWORK);
    }

    public function testTheConstructorRefusesANonConcordiumNetwork(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConcordiumFacilitator($this->node(), self::SPONSOR, self::SPONSOR_SEED, 'eip155:84532');
    }

    public function testAnotherNetworkIsRefusedBeforeTheNode(): void
    {
        $req = $this->req();
        $req['network'] = ConcordiumExact::MAINNET_NETWORK;

        $answer = $this->facilitator()->verify($this->payment(), $req);
        $this->assertFalse($answer['isValid']);
        $this->assertSame('invalid_network', $answer['invalidReason']);

        $node = $this->node();
        $result = $this->facilitator($node)->settle($this->payment(), $req);
        $this->assertFalse($result['success']);
        $this->assertSame('invalid_network', $result['errorReason']);
        $this->assertSame('', $result['transaction']);
        $this->assertSame([], $node->sent);
    }

    public function testSettleKeepsTheHashWhenTheStatusCannotBeRead(): void
    {
        $node = $this->node();
        $node->statusError = new FacilitatorException('unreadable GetBlockItemStatus');

        $result = $this->facilitator($node)->settle($this->payment(), $this->req());
        $this->assertFalse($result['success']);
        $this->assertSame('settlement_pending', $result['errorReason']);
        $this->assertSame(self::HASH, $result['transaction']);
        $this->assertCount(1, $node->sent);
    }
}

final class FakeConcordiumNode implements ConcordiumNode
{
    public ?array $info = null;
    public int $nonce = 42;
    /** @var list<array> */
    public array $sent = [];
    public ?FacilitatorException $statusError = null;
    public array $status = ['finalized' => false, 'success' => false, 'amount' => null, 'receiver' => null, 'transaction' => ''];

    public function __construct(private readonly string $address)
    {
    }

    public function accountInfo(string $address): ?array
    {
        return $address === $this->address ? $this->info : null;
    }

    public function nextNonce(string $address): int
    {
        if ($address !== $this->address) {
            throw new FacilitatorException('unknown account');
        }

        return $this->nonce;
    }

    public function send(array $tx): string
    {
        $this->sent[] = $tx;

        return ConcordiumFacilitatorTest::HASH;
    }

    public function status(string $hash): array
    {
        if (null !== $this->statusError) {
            throw $this->statusError;
        }

        return $this->status;
    }
}
