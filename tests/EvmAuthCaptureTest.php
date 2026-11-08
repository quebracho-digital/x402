<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quebracho\X402\AuthCapture;
use Quebracho\X402\AuthCaptureOperator;
use Quebracho\X402\ChainSender;
use Quebracho\X402\EvmAuthCapture;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Networks;
use Quebracho\X402\Rpc;
use Quebracho\X402\Schemes;
use Quebracho\X402\Signer;

/**
 * The `auth-capture` scheme end to end, offline: our buyer pays an offer our operator publishes, the
 * operator verifies and sends authorize, capture and void to a scripted node.
 */
final class EvmAuthCaptureTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const NETWORK = 'eip155:84532';
    private const PAY_TO = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
    private const TX = '0xabababababababababababababababababababababababababababababababab';
    // Anvil's well-known test keys: public, never funded on a real chain.
    private const BUYER_KEY = '0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80';
    private const GAS_KEY = '0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a';

    /** @var list<array{method: string, params: array}> */
    private array $calls = [];

    private function clock(): \Closure
    {
        return fn (): int => self::NOW;
    }

    private function buyer(): EvmAuthCapture
    {
        return new EvmAuthCapture(new Signer(self::BUYER_KEY), $this->clock());
    }

    private function operator(array $answers = []): AuthCaptureOperator
    {
        $calls = &$this->calls;
        $client = new class($answers, $calls) implements ClientInterface {
            public function __construct(private array $answers, private array &$calls)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $body = json_decode((string) $request->getBody(), true);
                $this->calls[] = ['method' => $body['method'], 'params' => $body['params']];
                $a = $this->answers[$body['method']] ?? null;

                return new Response(200, [], json_encode($a instanceof \RuntimeException ? ['error' => ['message' => $a->getMessage()]] : ['result' => $a]));
            }
        };
        $f = new Psr17Factory();
        $sender = new ChainSender(new Rpc($client, $f, $f, 'https://rpc.example'), new Signer(self::GAS_KEY), self::NETWORK, fn (string $name, callable $run) => $run(), null, 0, 3);

        return new AuthCaptureOperator($sender, new EvmAuthCapture(clock: $this->clock()));
    }

    private function offer(array $extra = []): array
    {
        $offer = $this->operator()->offer(Networks::requirements(self::NETWORK, '0.02', self::PAY_TO), self::NOW + 7200);
        $offer['extra'] = $extra + $offer['extra'];

        return $offer;
    }

    private function pay(array $req): array
    {
        return $this->buyer()->pay(['resource' => ['url' => 'mcp://tool/analyze_terms_start']], $req);
    }

    private function sendable(): array
    {
        return ['eth_gasPrice' => '0x1', 'eth_maxPriorityFeePerGas' => '0x1', 'eth_estimateGas' => '0x1', 'eth_getTransactionCount' => '0x0', 'eth_sendRawTransaction' => self::TX, 'eth_getTransactionReceipt' => ['status' => '0x1']];
    }

    public function testTheOfferNamesUsAsOperator(): void
    {
        $offer = $this->offer();

        self::assertSame('auth-capture', $offer['scheme']);
        self::assertSame('20000', $offer['amount']);
        self::assertSame((new Signer(self::GAS_KEY))->address(), $offer['extra']['captureAuthorizer']);
        self::assertSame('deferred', $offer['extra']['captureMode']);
        self::assertNull(EvmAuthCapture::offer($offer, self::NOW));
    }

    public function testABuyerPaysWhatTheSellerVerifies(): void
    {
        $req = $this->offer();
        $payment = $this->pay($req);
        $auth = $payment['payload']['authorization'];

        self::assertSame(AuthCapture::V1_1['eip3009Collector'], $auth['to']);
        self::assertSame('20000', $auth['value']);
        self::assertSame((string) (self::NOW + $req['maxTimeoutSeconds']), $auth['validBefore']);
        self::assertArrayNotHasKey('saltNonce', $payment['payload']);
        self::assertNull((new EvmAuthCapture(clock: $this->clock()))->verify($payment, $req));
        self::assertSame((new Signer(self::BUYER_KEY))->address(), (new EvmAuthCapture())->payer($payment));
    }

    public function testABoundOfferGetsASaltNonce(): void
    {
        // We do not sell bound (no receiver authorizer), but a buyer can pay a server that does.
        $req = $this->offer(['receiverAuthorizer' => '0x1111111111111111111111111111111111111111']);
        $payload = $this->pay($req)['payload'];

        self::assertSame(AuthCapture::boundSalt('0x1111111111111111111111111111111111111111', AuthCapture::ZERO, $payload['saltNonce']), $payload['salt']);
    }

    /**
     * @return iterable<string, array{\Closure(array): array, string}>
     */
    public static function tampered(): iterable
    {
        $auth = fn (string $k, string $v) => function (array $p) use ($k, $v) {
            $p['payload']['authorization'][$k] = $v;

            return $p;
        };
        yield 'other collector' => [$auth('to', self::PAY_TO), 'invalid_auth_capture_evm_token_collector_mismatch'];
        yield 'less money' => [$auth('value', '1'), 'invalid_auth_capture_evm_amount_mismatch'];
        yield 'valid after now' => [$auth('validAfter', '1'), 'invalid_auth_capture_evm_authorization_not_yet_valid'];
        yield 'expires now' => [$auth('validBefore', (string) (self::NOW + 6)), 'invalid_auth_capture_evm_authorization_expired'];
        yield 'too long' => [$auth('validBefore', (string) (self::NOW + 3600)), 'invalid_auth_capture_evm_authorization_expired'];
        yield 'other nonce' => [$auth('nonce', '0x'.str_repeat('ab', 32)), 'invalid_auth_capture_evm_signature'];
        yield 'nonce spelled twice' => [$auth('nonce', '0x'.str_repeat('AB', 32)), 'invalid_auth_capture_evm_signature'];
        yield 'other salt' => [function (array $p) {
            $p['payload']['salt'] = '0x'.str_repeat('0', 63).'1';

            return $p;
        }, 'invalid_auth_capture_evm_nonce_mismatch'];
        yield 'salt in capitals' => [function (array $p) {
            $p['payload']['salt'] = '0x'.str_repeat('A', 64);

            return $p;
        }, 'invalid_auth_capture_evm_payload_format'];
        yield 'a saltNonce on an unbound offer' => [function (array $p) {
            $p['payload']['saltNonce'] = '0x'.str_repeat('0', 64);

            return $p;
        }, 'invalid_auth_capture_evm_payload_format'];
        yield 'an exact echo' => [function (array $p) {
            $p['accepted']['scheme'] = 'exact';

            return $p;
        }, 'invalid_auth_capture_evm_scheme'];
        yield 'another network' => [function (array $p) {
            $p['accepted']['network'] = 'eip155:8453';

            return $p;
        }, 'invalid_auth_capture_evm_network_mismatch'];
        yield 'short signature' => [function (array $p) {
            $p['payload']['signature'] = '0x11';

            return $p;
        }, 'invalid_auth_capture_evm_payload_format'];
    }

    #[DataProvider('tampered')]
    public function testATamperedPaymentIsRefused(\Closure $tamper, string $reason): void
    {
        $req = $this->offer();

        self::assertSame($reason, (new EvmAuthCapture(clock: $this->clock()))->verify($tamper($this->pay($req)), $req));
    }

    public function testAPaymentForOtherTermsFailsTheNonce(): void
    {
        // The buyer signed a hold for a later deadline than we offer: every PaymentInfo field is in the nonce.
        $req = $this->offer();
        $payment = $this->pay(['extra' => ['captureDeadline' => self::NOW + 9000] + $req['extra']] + $req);

        self::assertSame('invalid_auth_capture_evm_nonce_mismatch', (new EvmAuthCapture(clock: $this->clock()))->verify($payment, $req));
    }

    /**
     * @return iterable<string, array{array, string}>
     */
    public static function offers(): iterable
    {
        yield 'a fee range' => [['minFeeBps' => 0, 'maxFeeBps' => 100], 'invalid_auth_capture_evm_extra'];
        yield 'a fee to nobody' => [['minFeeBps' => 100, 'maxFeeBps' => 100], 'invalid_auth_capture_evm_extra'];
        yield 'sync capture' => [['captureMode' => 'sync'], 'invalid_auth_capture_evm_extra'];
        yield 'the authorization flow' => [['paymentFlow' => 'authorization'], 'invalid_auth_capture_evm_unsupported_payment_flow'];
        yield 'autoCapture' => [['autoCapture' => true], 'invalid_auth_capture_evm_unsupported_payment_flow'];
        yield 'a custom operator' => [['operatorType' => 'custom'], 'invalid_auth_capture_evm_unsupported_operator_type'];
        yield 'a policy' => [['policy' => '0x1111111111111111111111111111111111111111'], 'invalid_auth_capture_evm_policy'];
        yield 'a receiver authorizer' => [['receiverAuthorizer' => '0x1111111111111111111111111111111111111111'], 'invalid_auth_capture_evm_extra'];
        yield 'permit2' => [['assetTransferMethod' => 'permit2'], 'invalid_auth_capture_evm_unsupported_asset_transfer_method'];
        yield 'an unknown escrow' => [['authCaptureEscrow' => '0x1111111111111111111111111111111111111111'], 'invalid_auth_capture_evm_extra'];
        yield 'refund before capture' => [['refundDeadline' => self::NOW + 100], 'invalid_auth_capture_evm_deadline_ordering'];
        yield 'a hold shorter than the signature' => [['captureDeadline' => self::NOW + 30, 'refundDeadline' => self::NOW + 30], 'invalid_auth_capture_evm_deadline_ordering'];
        yield 'a deadline as a string' => [['captureDeadline' => (string) (self::NOW + 7200)], 'invalid_auth_capture_evm_extra'];
    }

    #[DataProvider('offers')]
    public function testOffersWeDoNotSell(array $extra, string $reason): void
    {
        self::assertSame($reason, EvmAuthCapture::offer($this->offer($extra), self::NOW));
    }

    public function testAnExpiredHoldIsNotOffered(): void
    {
        self::assertSame('invalid_auth_capture_evm_deadline_ordering', EvmAuthCapture::offer($this->offer(), self::NOW + 7200 - 10));
    }

    public function testSchemesArePickedByNameAndNetwork(): void
    {
        $exact = new EvmExact();
        $hold = new EvmAuthCapture();

        self::assertSame($hold, Schemes::pick([$exact, $hold], $this->offer()));
        self::assertSame($exact, Schemes::pick([$exact, $hold], Networks::requirements(self::NETWORK, '0.02', self::PAY_TO)));
        self::assertNull(Schemes::pick([$exact], $this->offer()));
    }

    public function testTheOperatorSimulatesThenSendsAuthorize(): void
    {
        $req = $this->offer();
        $payment = $this->pay($req);

        $verified = $this->operator(['eth_call' => '0x'])->verify($payment, $req);
        self::assertTrue($verified['isValid'], $verified['invalidReason'] ?? '');
        self::assertSame(AuthCapture::V1_1['escrow'], $this->calls[0]['params'][0]['to']);

        $this->calls = [];
        $settled = $this->operator($this->sendable())->settle($payment, $req);
        self::assertTrue($settled['success']);
        self::assertSame(self::NETWORK, $settled['network']);
        // The authorize carries the whole amount and the buyer's signature, to the escrow.
        $estimate = $this->calls[2]['params'][0];
        self::assertSame(AuthCapture::V1_1['escrow'], $estimate['to']);
        self::assertSame(AuthCapture::authorizeCall(EvmAuthCapture::paymentInfoOf($payment, $req), '20000', AuthCapture::V1_1['eip3009Collector'], $payment['payload']['signature']), $estimate['data']);
    }

    public function testTheOperatorRefusesWhatItCannotHold(): void
    {
        $req = $this->offer();
        $payment = $this->pay($req);
        $other = ['extra' => ['captureAuthorizer' => self::PAY_TO] + $req['extra']] + $req;

        self::assertSame('invalid_auth_capture_evm_operator_not_admitted', $this->operator()->verify($this->pay($other), $other)['invalidReason']);
        self::assertSame('invalid_auth_capture_evm_simulation_failed', $this->operator(['eth_call' => new \RuntimeException('execution reverted')])->verify($payment, $req)['invalidReason']);
        self::assertSame('invalid_auth_capture_evm_operator_not_admitted', $this->operator($this->sendable())->settle($this->pay($other), $other)['errorReason']);
        self::assertSame([], array_filter($this->calls, fn (array $c) => 'eth_sendRawTransaction' === $c['method']));
    }

    public function testCaptureVoidAndState(): void
    {
        $req = $this->offer();
        $payment = $this->pay($req);
        $info = EvmAuthCapture::paymentInfoOf($payment, $req);

        self::assertTrue($this->operator($this->sendable())->capture($info)['success']);
        self::assertSame(AuthCapture::captureCall($info, '20000', '0', AuthCapture::ZERO), $this->calls[2]['params'][0]['data']);

        $this->calls = [];
        self::assertTrue($this->operator($this->sendable())->void($info)['success']);
        self::assertSame(AuthCapture::voidCall($info), $this->calls[2]['params'][0]['data']);

        $this->calls = [];
        $words = '0x'.str_pad('1', 64, '0', \STR_PAD_LEFT).str_pad('0', 64, '0', \STR_PAD_LEFT).str_pad(dechex(20000), 64, '0', \STR_PAD_LEFT);
        self::assertSame(['collected' => true, 'capturable' => '0', 'refundable' => '20000'], $this->operator(['eth_call' => $words])->state($info));
        self::assertSame(AuthCapture::stateCall(AuthCapture::hash(84532, $info)), $this->calls[0]['params'][0]['data']);
    }

    public function testAFixedFeeGoesToItsRecipient(): void
    {
        $fees = '0x1111111111111111111111111111111111111111';
        $req = $this->operator()->offer(Networks::requirements(self::NETWORK, '0.02', self::PAY_TO), self::NOW + 7200, feeBps: 250, feeRecipient: $fees);
        $info = EvmAuthCapture::paymentInfoOf($this->pay($req), $req);

        $this->operator($this->sendable())->capture($info);
        self::assertSame(AuthCapture::captureCall($info, '20000', '500', $fees), $this->calls[2]['params'][0]['data']);
    }

    public function testNoLifecycleOnAnotherOperatorsPayment(): void
    {
        $req = $this->offer();
        $info = ['operator' => self::PAY_TO] + EvmAuthCapture::paymentInfoOf($this->pay($req), $req);

        $this->expectException(\InvalidArgumentException::class);
        $this->operator($this->sendable())->void($info);
    }
}
