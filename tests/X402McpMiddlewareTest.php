<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileClaims;
use Quebracho\X402\Networks;
use Quebracho\X402\Scheme;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\X402McpMiddleware;

final class X402McpMiddlewareTest extends TestCase
{
    use TempClaims;

    private int $runs = 0;
    private \Closure $claims;

    protected function setUp(): void
    {
        // One durable store per test, shared by every middleware it builds.
        $this->claims = (new FileClaims($this->claimsDir()))->__invoke(...);
    }

    /**
     * @param array<string, \Closure> $hooks  validate, payer, duration, verify
     * @param ?Scheme                 $scheme default: EvmExact on the real clock
     */
    private function middleware(?\Closure $settle = null, bool $upfront = false, array $hooks = [], ?Scheme $scheme = null): X402McpMiddleware
    {
        $req = Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
        if ($upfront) {
            $req['extra']['paymentFlow'] = 'upfront';
        }
        $f = new Psr17Factory();

        return new X402McpMiddleware([$scheme ?? new EvmExact()], fn (array $params) => 'wisdom' === $params['name'] ? [$req] : null, $this->claims, $f, $f, $settle ?? Settlement::mock(), ...$hooks);
    }

    /** A tool server answering id 2, as JSON or as a buffered SSE stream. */
    private function handler(array $result = ['content' => [['type' => 'text', 'text' => 'paid content']]], bool $sse = false): RequestHandlerInterface
    {
        $json = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => $result]);
        $response = $sse
            ? new Response(200, ['Content-Type' => 'text/event-stream'], "event: message\ndata: {\"jsonrpc\":\"2.0\",\"method\":\"notifications/progress\"}\n\nevent: message\ndata: $json\n\n")
            : new Response(200, ['Content-Type' => 'application/json'], $json);

        return new class($this->runs, $response) implements RequestHandlerInterface {
            public function __construct(private int &$runs, private ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->runs;

                return $this->response;
            }
        };
    }

    private static function call(string $tool, ?array $payment = null): ServerRequest
    {
        $params = ['name' => $tool, 'arguments' => []];
        if (null !== $payment) {
            $params['_meta'][X402McpMiddleware::PAYMENT] = $payment;
        }

        return new ServerRequest('POST', 'https://example.test/mcp', ['Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => $params]));
    }

    /** @param int $age seconds ago it was signed: its validity left is maxTimeoutSeconds - $age */
    private function paidCall(int $age = 0, ?\Closure $clock = null): ServerRequest
    {
        $required = self::toolResult($this->middleware()->process(self::call('wisdom'), $this->handler()))['structuredContent'];
        $buyer = new EvmExact(new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318'), $clock ?? fn () => time() - $age);

        return self::call('wisdom', $buyer->pay($required, $required['accepts'][0]));
    }

    private static function toolResult(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if (str_contains($response->getHeaderLine('Content-Type'), 'event-stream')) {
            preg_match_all('/^data: (.*)$/m', $body, $m);
            $body = end($m[1]);
        }

        return json_decode($body, true)['result'];
    }

    public function testFreeToolsPassThrough(): void
    {
        $this->middleware()->process(self::call('echo'), $this->handler());
        self::assertSame(1, $this->runs);
    }

    public function testUnpaidCallGetsPaymentRequiredInBandWithoutRunning(): void
    {
        $result = self::toolResult($this->middleware()->process(self::call('wisdom'), $this->handler()));
        self::assertTrue($result['isError']);
        self::assertSame(2, $result['structuredContent']['x402Version']);
        self::assertSame('mcp://tool/wisdom', $result['structuredContent']['resource']['url']);
        self::assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true));
        self::assertSame(0, $this->runs);
    }

    public function testPaidCallRunsAndCarriesTheReceipt(): void
    {
        $result = self::toolResult($this->middleware()->process($this->paidCall(), $this->handler()));
        self::assertSame('paid content', $result['content'][0]['text']);
        self::assertTrue($result['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
        self::assertSame('0x2c7536E3605D9C16a7a3D7b1898e529396a65c23', $result['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['payer']);
    }

    public function testReceiptGoesIntoAStreamedAnswerToo(): void
    {
        $result = self::toolResult($this->middleware()->process($this->paidCall(), $this->handler(sse: true)));
        self::assertTrue($result['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
    }

    public function testAPayerOnTheSitesOfacListIsRefusedWithoutADispatcherAndNothingRuns(): void
    {
        $file = sys_get_temp_dir().'/ofac-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($file, json_encode(['published' => '2999-01-01T00:00:00Z', 'addresses' => ['ETH' => ['0x2c7536E3605D9C16a7a3D7b1898e529396a65c23']]]));
        try {
            $result = self::toolResult($this->middleware(hooks: ['ofac' => new \Quebracho\X402\OfacSdn($file)])->process($this->paidCall(), $this->handler()));
        } finally {
            unlink($file);
        }

        self::assertSame(\Quebracho\X402\OfacSdn::REASON, $result['structuredContent']['error']);
        self::assertSame(0, $this->runs);
    }

    public function testAServerRequestWithTheCallsIdIsNotTakenForItsAnswer(): void
    {
        // The server's own requests (sampling, elicitation) have their own id space: id 2 can be one of them.
        $request = '{"jsonrpc":"2.0","id":2,"method":"sampling/createMessage","params":{}}';
        $answer = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['content' => [['type' => 'text', 'text' => 'paid content']]]]);
        $response = new Response(200, ['Content-Type' => 'text/event-stream'], "event: message\ndata: $request\n\nevent: message\ndata: $answer\n\n");
        $server = new class($response) implements RequestHandlerInterface {
            public function __construct(private ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
        $settled = 0;
        $settle = function (array $payment, array $req) use (&$settled): array {
            ++$settled;

            return Settlement::mock()($payment, $req);
        };

        $body = (string) $this->middleware($settle)->process($this->paidCall(), $server)->getBody();

        self::assertStringContainsString("data: $request\n", $body, 'the server request goes out untouched');
        self::assertSame(1, $settled);
        self::assertTrue(self::toolResult(new Response(200, ['Content-Type' => 'text/event-stream'], $body))['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
    }

    public function testTamperedPaymentIsRefusedWithTheReason(): void
    {
        $request = $this->paidCall();
        $message = json_decode((string) $request->getBody(), true);
        $message['params']['_meta'][X402McpMiddleware::PAYMENT]['payload']['authorization']['value'] = '1';
        $result = self::toolResult($this->middleware()->process($request->withBody((new Psr17Factory())->createStream(json_encode($message))), $this->handler()));
        self::assertSame('invalid_exact_evm_payload_authorization_value_mismatch', $result['structuredContent']['error']);
        self::assertSame(0, $this->runs);
    }

    public function testFailedToolIsNotCharged(): void
    {
        $settled = false;
        $settle = function () use (&$settled) {
            $settled = true;

            return ['success' => true];
        };
        $result = self::toolResult($this->middleware($settle)->process($this->paidCall(), $this->handler(['isError' => true, 'content' => []])));
        self::assertFalse($settled);
        self::assertArrayNotHasKey('_meta', $result);
    }

    public function testFailedSettlementWithholdsTheToolOutput(): void
    {
        $settle = fn () => ['success' => false, 'errorReason' => 'insufficient_funds'];
        $response = $this->middleware($settle)->process($this->paidCall(), $this->handler());
        $result = self::toolResult($response);
        self::assertTrue($result['isError']);
        self::assertSame('insufficient_funds', $result['structuredContent']['error']);
        self::assertStringNotContainsString('paid content', (string) $response->getBody());
    }

    public function testBatchWithAPaidCallIsRejected(): void
    {
        $batch = '['.(string) self::call('echo')->getBody().','.(string) self::call('wisdom')->getBody().']';
        $response = $this->middleware()->process(new ServerRequest('POST', 'https://example.test/mcp', [], $batch), $this->handler());
        self::assertSame(-32600, json_decode((string) $response->getBody(), true)['error']['code']);
        self::assertSame(0, $this->runs);
    }

    public function testPaidNotificationGetsNoAnswerAndDoesNotRun(): void
    {
        $notification = json_encode(['jsonrpc' => '2.0', 'method' => 'tools/call', 'params' => ['name' => 'wisdom']]);
        $response = $this->middleware()->process(new ServerRequest('POST', 'https://example.test/mcp', [], $notification), $this->handler());
        self::assertSame(202, $response->getStatusCode());
        self::assertSame('', (string) $response->getBody());
        self::assertSame(0, $this->runs);
    }

    public function testAPaymentBuysOneDeliveryEvenUnsettled(): void
    {
        $call = $this->paidCall();
        $settled = 0;
        $settle = function () use (&$settled) {
            ++$settled;

            return ['success' => true, 'transaction' => '0xabc', 'network' => 'eip155:84532'];
        };
        $this->middleware($settle)->process($call, $this->handler());
        $again = self::toolResult($this->middleware($settle)->process($call, $this->handler()));
        self::assertSame('duplicate_settlement', $again['structuredContent']['error']);
        self::assertSame(1, $this->runs);
        self::assertSame(1, $settled);
    }

    public static function brokenFacilitators(): iterable
    {
        yield 'throws' => [fn () => throw new \RuntimeException('timeout'), 'unexpected_settle_error'];
        yield 'string false' => [fn () => ['success' => 'false'], 'unexpected_settle_error'];
        yield 'null' => [fn () => null, 'unexpected_settle_error'];
        yield 'odd reason' => [fn () => ['success' => false, 'errorReason' => ['x']], 'unexpected_settle_error'];
        yield 'pending' => [fn () => ['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => '0xabc'], 'settlement_pending'];
    }

    #[DataProvider('brokenFacilitators')]
    public function testABrokenFacilitatorWithholdsTheOutput(\Closure $settle, string $reason): void
    {
        $response = $this->middleware($settle)->process($this->paidCall(), $this->handler());
        self::assertSame($reason, self::toolResult($response)['structuredContent']['error']);
        self::assertStringNotContainsString('paid content', (string) $response->getBody());
    }

    public function testATransportRefusalIsPassedOnUncharged(): void
    {
        $refusal = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(404, [], 'Session not found');
            }
        };
        self::assertSame(404, $this->middleware()->process($this->paidCall(), $refusal)->getStatusCode());
    }

    public function testUpfrontSettlesBeforeTheToolRuns(): void
    {
        $tx = fn () => ['success' => true, 'transaction' => '0x'.str_repeat('ab', 32), 'network' => 'eip155:84532'];
        $required = self::toolResult($this->middleware(upfront: true)->process(self::call('wisdom'), $this->handler()))['structuredContent'];
        $buyer = new EvmExact(new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318'));
        $pay = fn () => self::call('wisdom', $buyer->pay($required, $required['accepts'][0]));

        $refused = self::toolResult($this->middleware(fn () => ['success' => false, 'errorReason' => 'insufficient_funds'], true)->process($pay(), $this->handler()));
        self::assertSame(['insufficient_funds', 0], [$refused['structuredContent']['error'], $this->runs]);

        $paid = self::toolResult($this->middleware($tx, true)->process($pay(), $this->handler()));
        self::assertTrue($paid['_meta']['x402/payment-response']['success']);
        // Charged first: a failing tool still carries the receipt.
        $failed = self::toolResult($this->middleware($tx, true)->process($pay(), $this->handler(['isError' => true, 'content' => []])));
        self::assertTrue($failed['_meta']['x402/payment-response']['success']);
        self::assertSame(2, $this->runs);
    }

    public function testUpfrontReceiptRidesOnAJsonRpcError(): void
    {
        $tx = fn () => ['success' => true, 'transaction' => '0x'.str_repeat('ab', 32), 'network' => 'eip155:84532'];
        $required = self::toolResult($this->middleware(upfront: true)->process(self::call('wisdom'), $this->handler()))['structuredContent'];
        $request = self::call('wisdom', (new EvmExact(new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318')))->pay($required, $required['accepts'][0]));
        $errors = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":2,"error":{"code":-32603,"message":"tool broke","data":"trace id 7"}}');
            }
        };

        $out = json_decode((string) $this->middleware($tx, true)->process($request, $errors)->getBody(), true);
        self::assertSame('tool broke', $out['error']['message']);
        self::assertSame('trace id 7', $out['error']['data']['data']);
        self::assertSame('0x'.str_repeat('ab', 32), $out['error']['data'][X402McpMiddleware::PAYMENT_RESPONSE]['transaction']);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function errorData(): iterable
    {
        yield 'a forged receipt' => ['{"x402/payment-response":{"success":true,"transaction":"0xforged"},"hint":"x"}', ['hint' => 'x']];
        yield 'a list' => ['[1,2]', ['data' => [1, 2]]];
        yield 'no data' => ['null', []];
        yield 'a string' => ['"see logs"', ['data' => 'see logs']];
        yield 'an empty object' => ['{}', []];
    }

    #[DataProvider('errorData')]
    public function testTheMiddlewaresReceiptAlwaysWinsInErrorData(string $data, array $kept): void
    {
        $tx = fn () => ['success' => true, 'transaction' => '0x'.str_repeat('ab', 32), 'network' => 'eip155:84532'];
        $required = self::toolResult($this->middleware(upfront: true)->process(self::call('wisdom'), $this->handler()))['structuredContent'];
        $request = self::call('wisdom', (new EvmExact(new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318')))->pay($required, $required['accepts'][0]));
        $errors = new class($data) implements RequestHandlerInterface {
            public function __construct(private readonly string $data)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":2,"error":{"code":-32603,"message":"tool broke","data":'.$this->data.'}}');
            }
        };

        $data = json_decode((string) $this->middleware($tx, true)->process($request, $errors)->getBody(), true)['error']['data'];
        self::assertSame('0x'.str_repeat('ab', 32), $data[X402McpMiddleware::PAYMENT_RESPONSE]['transaction']);
        unset($data[X402McpMiddleware::PAYMENT_RESPONSE]);
        self::assertSame($kept, $data);
    }

    public function testATooShortSignatureCostsNoFacilitatorRoundTrip(): void
    {
        $t = time();
        $request = $this->paidCall(clock: fn () => $t);
        $verified = 0;
        $verify = function () use (&$verified): ?string {
            ++$verified;

            return null;
        };
        $result = self::toolResult($this->middleware(hooks: ['duration' => fn () => 55, 'verify' => $verify], scheme: new EvmExact(clock: fn () => $t))->process($request, $this->handler()));
        self::assertSame('invalid_exact_evm_payload_authorization_valid_before', $result['structuredContent']['error']);
        self::assertSame([0, 0], [$verified, $this->runs]);
    }

    public function testTheTimeTheOnlineVerifyTookIsCountedBeforeTheClaim(): void
    {
        $now = $t = time();
        $request = $this->paidCall(clock: fn () => $t);
        $seller = new EvmExact(clock: function () use (&$now) {
            return $now;
        });
        // 60 s left fits a 50 s tool, until the facilitator takes 10 s to answer.
        $slow = function () use (&$now): ?string {
            $now += 10;

            return null;
        };
        $result = self::toolResult($this->middleware(hooks: ['duration' => fn () => 50, 'verify' => $slow], scheme: $seller)->process($request, $this->handler()));
        self::assertSame('invalid_exact_evm_payload_authorization_valid_before', $result['structuredContent']['error']);
        self::assertSame(0, $this->runs);

        // Not claimed: with a fast facilitator the same payment buys.
        $now = $t;
        $ok = $this->middleware(hooks: ['duration' => fn () => 50, 'verify' => fn () => null], scheme: $seller)->process($request, $this->handler());
        self::assertTrue(self::toolResult($ok)['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
    }

    public function testValidateRefusesBeforeAnythingIsReadClaimedOrRun(): void
    {
        $settled = 0;
        $settle = function () use (&$settled) {
            ++$settled;

            return Settlement::mock()(...\func_get_args());
        };
        $seen = null;
        $validate = function (array $params) use (&$seen): ?string {
            $seen = $params['name'];

            return 'text must not be empty';
        };
        $request = $this->paidCall();

        $out = json_decode((string) $this->middleware($settle, hooks: ['validate' => $validate])->process($request, $this->handler())->getBody(), true);
        self::assertSame(['jsonrpc' => '2.0', 'id' => 2, 'error' => ['code' => -32602, 'message' => 'text must not be empty', 'data' => ['reason' => 'invalid_params']]], $out);
        self::assertSame(['wisdom', 0, 0], [$seen, $this->runs, $settled]);
        // Unpaid too: the buyer is not asked to sign for params we would refuse.
        self::assertArrayHasKey('error', json_decode((string) $this->middleware(hooks: ['validate' => $validate])->process(self::call('wisdom'), $this->handler())->getBody(), true));
        // Not claimed: the same payment still buys once the params are fine.
        $ok = $this->middleware(hooks: ['validate' => fn () => null])->process($request, $this->handler());
        self::assertSame('paid content', self::toolResult($ok)['content'][0]['text']);
    }

    public function testValidateNeverTouchesAFreeCall(): void
    {
        // price() NULL is free: a refusing validate must neither block nor price it.
        $asked = 0;
        $validate = function () use (&$asked): string {
            ++$asked;

            return 'invalid_params';
        };
        $response = $this->middleware(hooks: ['validate' => $validate])->process(self::call('echo'), $this->handler());
        self::assertSame([0, 1], [$asked, $this->runs]);
        self::assertSame('paid content', self::toolResult($response)['content'][0]['text']);
    }

    public function testAPayerTheCallIsNotForTakesNoClaim(): void
    {
        $seen = [];
        $payer = function (array $params, string $from) use (&$seen): ?string {
            $seen = [$params['name'], $from];

            return 'not_the_jobs_payer';
        };
        $request = $this->paidCall();

        $result = self::toolResult($this->middleware(hooks: ['payer' => $payer])->process($request, $this->handler()));
        self::assertSame('not_the_jobs_payer', $result['structuredContent']['error']);
        self::assertSame(['wisdom', '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23'], $seen);
        self::assertSame(0, $this->runs);

        $ok = $this->middleware(hooks: ['payer' => fn () => null])->process($request, $this->handler());
        self::assertTrue(self::toolResult($ok)['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
    }

    public function testAPayerRefusalChargesNothingEvenUpfront(): void
    {
        $settled = 0;
        $settle = function () use (&$settled) {
            ++$settled;

            return ['success' => true, 'transaction' => '0x'.str_repeat('ab', 32), 'network' => 'eip155:84532'];
        };
        $required = self::toolResult($this->middleware(upfront: true)->process(self::call('wisdom'), $this->handler()))['structuredContent'];
        $request = self::call('wisdom', (new EvmExact(new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318')))->pay($required, $required['accepts'][0]));

        // Upfront settles before the tool runs: the predicate must refuse before that, not only before the run.
        $result = self::toolResult($this->middleware($settle, true, ['payer' => fn () => 'not_the_jobs_payer'])->process($request, $this->handler()));
        self::assertSame(['not_the_jobs_payer', 0, 0], [$result['structuredContent']['error'], $settled, $this->runs]);
        // Not claimed: the same signature still buys once the payer is accepted.
        $ok = self::toolResult($this->middleware($settle, true, ['payer' => fn () => null])->process($request, $this->handler()));
        self::assertSame([true, 1, 1], [$ok['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success'], $settled, $this->runs]);
    }

    public function testASlowToolRefusesASignatureThatWouldExpireBeforeTheSettle(): void
    {
        // One pinned clock for buyer and seller: signed at $t, validBefore = $t + 60.
        $t = time();
        $request = $this->paidCall(clock: fn () => $t);
        $seller = new EvmExact(clock: fn () => $t);
        // 60 s left: a tool of 55 s plus EvmExact::SLACK (6) does not fit.
        $result = self::toolResult($this->middleware(hooks: ['duration' => fn (array $params) => 55], scheme: $seller)->process($request, $this->handler()));
        self::assertSame('invalid_exact_evm_payload_authorization_valid_before', $result['structuredContent']['error']);
        self::assertSame(0, $this->runs);

        // Not claimed: 54 + 6 = 60 fits exactly, and that payment still buys.
        $ok = $this->middleware(hooks: ['duration' => fn () => 54], scheme: $seller)->process($request, $this->handler());
        self::assertTrue(self::toolResult($ok)['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
    }

    public function testADurationOnASchemeWithoutValidBeforeIsRefused(): void
    {
        $request = $this->paidCall();
        // A scheme for the same network that is not EvmExact: no expiry Sale can read.
        $other = new class implements Scheme {
            public function name(): string
            {
                return 'exact';
            }

            public function supports(string $network): bool
            {
                return true;
            }

            public function verify(array $payment, array $req): ?string
            {
                return null;
            }

            public function pay(array $required, array $req): array
            {
                return [];
            }

            public function payer(array $payment): ?string
            {
                return '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23';
            }
        };
        $result = self::toolResult($this->middleware(hooks: ['duration' => fn () => 10], scheme: $other)->process($request, $this->handler()));
        self::assertSame('unsupported_scheme', $result['structuredContent']['error']);
        self::assertSame(0, $this->runs);
        self::assertSame('paid content', self::toolResult($this->middleware(scheme: $other)->process($request, $this->handler()))['content'][0]['text']);
    }

    public function testAFastToolIsUnchangedByTheHooks(): void
    {
        // Regression: 10 s left, as today accepted (EvmExact's 6 s slack) when the tool declares no duration.
        $durations = ['duration' => fn (array $params) => 'wisdom' === $params['name'] ? 0 : 120];
        foreach ([[], $durations] as $hooks) {
            $result = self::toolResult($this->middleware(hooks: $hooks)->process($this->paidCall(age: 50), $this->handler()));
            self::assertTrue($result['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
        }
        self::assertSame(2, $this->runs);
    }

    public function testAFastToolIsMeasuredByItsOwnDurationNotTheOffersTimeout(): void
    {
        // Signed 50 s ago with the 60 s offer: 10 s left. A 4 s tool plus EvmExact::SLACK (6) fits, so it buys;
        // the pre-flight must not use maxTimeoutSeconds (60) as the worst case.
        $t = time();
        $request = $this->paidCall(clock: fn () => $t - 50);
        $result = self::toolResult($this->middleware(hooks: ['duration' => fn () => 4], scheme: new EvmExact(clock: fn () => $t))->process($request, $this->handler()));
        self::assertTrue($result['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success'] ?? null, json_encode($result));
        self::assertSame(1, $this->runs);
    }
}
