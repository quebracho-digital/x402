<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quebracho\X402\ChainFacilitator;
use Quebracho\X402\Eip712;
use Quebracho\X402\FacilitatorException;
use Quebracho\X402\HttpFacilitator;
use Quebracho\X402\Rpc;
use Quebracho\X402\Signer;

/**
 * HttpFacilitator (any URL, Circle) and ChainFacilitator against a scripted PSR-18 client.
 */
final class FacilitatorsTest extends TestCase
{
    private const BUYER = '0xf39Fd6e51aad88F6F4ce6aB8827279cffFb92266';
    private const PAY_TO = '0x70997970C51812dc3A010C7d01b50e0d17dc79C8';
    private const TX = '0xabababababababababababababababababababababababababababababababab';
    private const NETWORK = 'eip155:5042002';

    /** @var list<RequestInterface> */
    private array $sent = [];

    /**
     * A client answering each request with $answer($request), and recording it.
     */
    private function client(\Closure $answer): ClientInterface
    {
        return new class($answer, $this->sent) implements ClientInterface {
            public function __construct(private \Closure $answer, private array &$sent)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->sent[] = $request;

                return ($this->answer)($request);
            }
        };
    }

    private function http(array $responses, ?string $bearer = null, ?Signer $seller = null): HttpFacilitator
    {
        $f = new Psr17Factory();

        return new HttpFacilitator($this->client(function () use (&$responses) {
            return array_shift($responses);
        }), $f, $f, HttpFacilitator::CIRCLE.'/', $bearer, $seller, pollDelaySeconds: 0);
    }

    /**
     * A node answering JSON-RPC by method; a \RuntimeException answers with an error.
     */
    private function chain(array $answers): ChainFacilitator
    {
        $f = new Psr17Factory();
        $client = $this->client(function (RequestInterface $r) use ($answers) {
            $method = json_decode((string) $r->getBody(), true)['method'];
            $a = $answers[$method] ?? null;

            return new Response(200, [], json_encode($a instanceof \RuntimeException ? ['error' => ['message' => $a->getMessage()]] : ['result' => $a]));
        });

        return new ChainFacilitator(new Rpc($client, $f, $f, 'https://rpc.example'), new Signer('0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a'), self::NETWORK, fn (string $name, callable $run) => $run(), null, 0, 3);
    }

    private function payment(array $auth = []): array
    {
        return ['x402Version' => 2, 'payload' => [
            'signature' => '0x'.str_repeat('11', 32).str_repeat('22', 32).'1c',
            'authorization' => $auth + ['from' => self::BUYER, 'to' => self::PAY_TO, 'value' => '10000', 'validAfter' => '0', 'validBefore' => '9999999999', 'nonce' => '0x'.str_repeat('33', 32)],
        ]];
    }

    private function req(): array
    {
        return ['scheme' => 'exact', 'network' => self::NETWORK, 'amount' => '10000', 'asset' => '0x3600000000000000000000000000000000000000', 'payTo' => self::PAY_TO];
    }

    private function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function testCalldataEqualsViem(): void
    {
        $this->assertSame(
            '0xe3ee160e000000000000000000000000f39fd6e51aad88f6f4ce6ab8827279cfffb9226600000000000000000000000070997970c51812dc3a010c7d01b50e0d17dc79c80000000000000000000000000000000000000000000000000000000000002710000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000000002540be3ff3333333333333333333333333333333333333333333333333333333333333333000000000000000000000000000000000000000000000000000000000000001c11111111111111111111111111111111111111111111111111111111111111112222222222222222222222222222222222222222222222222222222222222222',
            ChainFacilitator::calldata($this->payment()),
        );
    }

    public function testChainSettleSendsAndWaitsForTheReceipt(): void
    {
        $result = $this->chain(['eth_gasPrice' => '0x5d21dba00', 'eth_maxPriorityFeePerGas' => '0x1', 'eth_estimateGas' => '0xea60', 'eth_getTransactionCount' => '0x7', 'eth_sendRawTransaction' => self::TX, 'eth_getTransactionReceipt' => ['status' => '0x1']])
            ->settle($this->payment(), $this->req());

        $this->assertTrue($result['success']);
        // The hash of what was signed, not whatever the node said.
        $raw = json_decode((string) $this->sent[4]->getBody(), true)['params'][0];
        $this->assertSame(Eip712::keccak(Eip712::raw($raw)), $result['transaction']);
        $methods = array_map(fn ($r) => json_decode((string) $r->getBody(), true)['method'], $this->sent);
        $this->assertSame(['eth_gasPrice', 'eth_maxPriorityFeePerGas', 'eth_estimateGas', 'eth_getTransactionCount', 'eth_sendRawTransaction', 'eth_getTransactionReceipt'], $methods);
    }

    public function testChainRefusesWithoutSpendingGas(): void
    {
        $cases = [
            'revert' => [$this->payment(), $this->req(), ['eth_gasPrice' => '0x1', 'eth_maxPriorityFeePerGas' => '0x1', 'eth_estimateGas' => new \RuntimeException('execution reverted: authorization is used')]],
            'other seller' => [$this->payment(['to' => '0x'.str_repeat('1', 40)]), $this->req(), []],
            'other amount' => [$this->payment(['value' => '1']), $this->req(), []],
            'expired' => [$this->payment(['validBefore' => '1']), $this->req(), []],
            'nonce spelled twice' => [$this->payment(['nonce' => '33']), $this->req(), []],
            'other network' => [$this->payment(), ['network' => 'eip155:84532'] + $this->req(), []],
        ];
        foreach ($cases as $case => [$payment, $req, $answers]) {
            $this->sent = [];
            $this->assertFalse($this->chain($answers)->settle($payment, $req)['success'], $case);
            $this->assertNotContains('eth_sendRawTransaction', array_map(fn ($r) => json_decode((string) $r->getBody(), true)['method'], $this->sent), $case);
        }
    }

    public function testChainKeepsTheHashWhenNotMinedOrReverted(): void
    {
        $base = ['eth_gasPrice' => '0x1', 'eth_maxPriorityFeePerGas' => '0x1', 'eth_estimateGas' => '0x1', 'eth_getTransactionCount' => '0x0', 'eth_sendRawTransaction' => self::TX];
        $pending = $this->chain($base + ['eth_getTransactionReceipt' => null])->settle($this->payment(), $this->req());
        $this->assertSame('settlement_pending', $pending['errorReason']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $pending['transaction']);
        // A send that errors after signing may have been broadcast: pending, with its hash.
        $lost = $this->chain(['eth_sendRawTransaction' => new \RuntimeException('already known')] + $base)->settle($this->payment(), $this->req());
        $this->assertSame('settlement_pending', $lost['errorReason']);
        $this->assertMatchesRegularExpression('/^0x[0-9a-f]{64}$/', $lost['transaction']);
        $reverted = $this->chain($base + ['eth_getTransactionReceipt' => ['status' => '0x0']])->settle($this->payment(), $this->req());
        $this->assertSame(['transaction_reverted', false], [$reverted['errorReason'], $reverted['success']]);
    }

    public function testChainGasCapSendsNothing(): void
    {
        $f = new Psr17Factory();
        $answers = ['eth_gasPrice' => '0x3b9aca00', 'eth_maxPriorityFeePerGas' => '0x1', 'eth_estimateGas' => '0xea60'];
        $client = $this->client(fn (RequestInterface $r) => new Response(200, [], json_encode(['result' => $answers[json_decode((string) $r->getBody(), true)['method']] ?? null])));
        $chain = new ChainFacilitator(new Rpc($client, $f, $f, 'https://rpc.example'), new Signer('0x5de4111afa1a4b94908f83103eb1f1706367c2e68ca870fc3fb9a804cdab365a'), self::NETWORK, fn ($n, callable $run) => $run(), '1000', 0, 3);
        $result = $chain->settle($this->payment(), $this->req());
        $this->assertSame(['gas_too_expensive', ''], [$result['errorReason'], $result['transaction']]);
        $this->assertCount(3, $this->sent);
    }

    public function testChainVerifySimulates(): void
    {
        $this->assertTrue($this->chain(['eth_call' => '0x'])->verify($this->payment(), $this->req())['isValid']);
        $this->assertFalse($this->chain(['eth_call' => new \RuntimeException('reverted')])->verify($this->payment(), $this->req())['isValid']);
    }

    public function testKeylessProofSignsTheExactBodySent(): void
    {
        $seller = new Signer('0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80');
        $this->assertTrue($this->http([$this->json(200, ['isValid' => true])], seller: $seller)->verify($this->payment(), $this->req())['isValid']);

        $request = $this->sent[0];
        $this->assertSame(HttpFacilitator::CIRCLE.'/verify', (string) $request->getUri());
        $this->assertSame(['x402Version' => 2, 'paymentPayload' => $this->payment(), 'paymentRequirements' => $this->req()], json_decode((string) $request->getBody(), true));
        $proof = json_decode(base64_decode(strtr($request->getHeaderLine('Facilitator-Seller-Proof'), '-_', '+/')), true);
        $digest = Eip712::hash(['name' => 'Circle Facilitator Seller Request', 'version' => '1', 'chainId' => 5042002], ['SellerRequest' => [
            ['name' => 'purpose', 'type' => 'string'], ['name' => 'method', 'type' => 'string'], ['name' => 'bodyHash', 'type' => 'bytes32'], ['name' => 'network', 'type' => 'string'],
            ['name' => 'payTo', 'type' => 'address'], ['name' => 'nonce', 'type' => 'bytes32'], ['name' => 'issuedAt', 'type' => 'uint64'], ['name' => 'expiresAt', 'type' => 'uint64'],
        ]], 'SellerRequest', ['purpose' => 'verify', 'method' => 'POST', 'bodyHash' => Eip712::keccak((string) $request->getBody()), 'network' => self::NETWORK, 'payTo' => $seller->address(), 'nonce' => $proof['nonce'], 'issuedAt' => $proof['issuedAt'], 'expiresAt' => $proof['expiresAt']]);
        $this->assertSame($seller->address(), Signer::recover($digest, $proof['signature']));
    }

    public function testBearerReplacesTheProof(): void
    {
        $this->http([$this->json(200, ['isValid' => true])], 'KEY', new Signer('0xac0974bec39a17e36ba4a6b4d238ff944bacb478cbed5efcae784d7bf4f2ff80'))->verify($this->payment(), $this->req());
        $this->assertSame('Bearer KEY', $this->sent[0]->getHeaderLine('Authorization'));
        $this->assertFalse($this->sent[0]->hasHeader('Facilitator-Seller-Proof'));
    }

    public function testPendingSettleIsPolled(): void
    {
        $result = $this->http([
            $this->json(200, ['success' => false, 'extensions' => ['settlement-status' => ['paymentId' => 'pay-1']]]),
            $this->json(200, ['status' => 'pending']),
            $this->json(200, ['status' => 'completed', 'transaction' => self::TX, 'network' => self::NETWORK, 'payer' => self::BUYER]),
        ], 'KEY')->settle($this->payment(), $this->req());

        $this->assertTrue($result['success']);
        $this->assertSame(HttpFacilitator::CIRCLE.'/status/pay-1', (string) $this->sent[2]->getUri());
    }

    public function testStillPendingAfterPollingKeepsThePaymentId(): void
    {
        $pending = $this->json(200, ['success' => false, 'extensions' => ['settlement-status' => ['paymentId' => 'pay-1']]]);
        $result = $this->http([$pending, ...array_fill(0, 5, $this->json(200, ['status' => 'pending']))], 'K')->settle($this->payment(), $this->req());
        $this->assertSame(['settlement_pending', 'pay-1'], [$result['errorReason'], $result['paymentId']]);
    }

    public function testOnlyAConsistentSettleCounts(): void
    {
        $good = ['success' => true, 'transaction' => self::TX, 'network' => self::NETWORK, 'payer' => strtolower(self::BUYER)];
        $this->assertSame($good, $this->http([$this->json(200, $good)], 'K')->settle($this->payment(), $this->req()));
        foreach (['no transaction' => ['transaction' => ''], 'other network' => ['network' => 'eip155:1'], 'other payer' => ['payer' => self::PAY_TO]] as $case => $change) {
            $this->assertFalse($this->http([$this->json(200, $change + $good)], 'K')->settle($this->payment(), $this->req())['success'], $case);
        }
        $this->assertSame('insufficient_funds', $this->http([$this->json(402, ['success' => false, 'errorReason' => 'insufficient_funds'])], 'K')->settle($this->payment(), $this->req())['errorReason']);
        foreach (['503 with a success body' => $this->json(503, $good), 'HTML 502' => new Response(502, [], '<html>')] as $case => $response) {
            try {
                $this->http([$response], 'K')->settle($this->payment(), $this->req());
                $this->fail($case);
            } catch (FacilitatorException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFeePayerFromSupported(): void
    {
        $kinds = ['kinds' => [['scheme' => 'exact', 'network' => 'solana:devnet', 'extra' => ['feePayer' => 'CKPK']]]];
        $this->assertSame('CKPK', $this->http([$this->json(200, $kinds)])->feePayer('solana:devnet'));
        $this->assertNull($this->http([$this->json(200, $kinds)])->feePayer(self::NETWORK));
    }
}
