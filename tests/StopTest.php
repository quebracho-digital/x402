<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\Budget;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileClaims;
use Quebracho\X402\FileLedger;
use Quebracho\X402\Networks;
use Quebracho\X402\PayingHttpClient;
use Quebracho\X402\PayingMcpClient;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\PurchaseOutcome;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\Stop;
use Quebracho\X402\X402McpMiddleware;
use Quebracho\X402\X402Middleware;

/**
 * The stop switch: a file, set by hand. While it exists the seller takes nothing new and the buyer signs
 * nothing; what was already paid is still delivered.
 */
final class StopTest extends TestCase
{
    use TempClaims {
        tearDown as private tidyClaims;
    }

    private const string PAY_TO = '0x000000000000000000000000000000000000dEaD';
    private const string KEY = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    private string $root;
    private string $flag;
    private Stop $stop;
    private int $runs = 0;
    private int $settles = 0;
    private int $verifies = 0;
    private int $claims = 0;
    /** What the tool answers, and what it does while running. */
    private \Closure $tool;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/x402-stop-'.bin2hex(random_bytes(4));
        $this->flag = $this->root.'/stop/x402.stop';
        $this->stop = new Stop($this->flag);
        $this->tool = static fn (): array => ['content' => [['type' => 'text', 'text' => 'ran']]];
    }

    protected function tearDown(): void
    {
        @chmod($this->root.'/locked', 0700);
        exec('rm -rf '.escapeshellarg($this->root));
        $this->tidyClaims();
    }

    private function handler(): RequestHandlerInterface
    {
        return new class($this) implements RequestHandlerInterface {
            public function __construct(private StopTest $test)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->test->serve();
            }
        };
    }

    /** @internal for the handler */
    public function serve(): ResponseInterface
    {
        ++$this->runs;

        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 2, 'result' => ($this->tool)()]));
    }

    private function settle(): \Closure
    {
        $settle = Settlement::mock();

        return function (array $payment, array $req) use ($settle): array {
            ++$this->settles;

            return $settle($payment, $req);
        };
    }

    private function counting(): \Closure
    {
        $claims = (new FileClaims($this->claimsDir()))->__invoke(...);

        return function (string $key) use ($claims): bool {
            ++$this->claims;

            return $claims($key);
        };
    }

    private function mcpSeller(bool $ledger = false, bool $upfront = false): X402McpMiddleware
    {
        $req = Networks::requirements('eip155:84532', '0.01', self::PAY_TO);
        if ($upfront) {
            $req['extra']['paymentFlow'] = 'upfront';
        }
        $f = new Psr17Factory();

        return new X402McpMiddleware([new EvmExact()], fn (array $p) => 'wisdom' === $p['name'] ? [$req] : null, $this->counting(), $f, $f, $this->settle(), verify: function (): ?string {
            ++$this->verifies;

            return null;
        }, ledger: $ledger ? new FileLedger($this->root.'/ledger') : null, stop: $this->stop);
    }

    private function httpSeller(): X402Middleware
    {
        $req = Networks::requirements('eip155:84532', '0.01', self::PAY_TO);
        $f = new Psr17Factory();

        return new X402Middleware([new EvmExact()], fn () => [$req], $this->counting(), $f, $f, $this->settle(), verify: function (): ?string {
            ++$this->verifies;

            return null;
        }, stop: $this->stop);
    }

    private static function call(string $tool, ?array $payment = null): ServerRequest
    {
        $params = ['name' => $tool, 'arguments' => []];
        if (null !== $payment) {
            $params['_meta'][X402McpMiddleware::PAYMENT] = $payment;
        }

        return new ServerRequest('POST', 'https://example.test/mcp', ['Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => $params]));
    }

    /** A payment the buyer signed for the MCP seller, while the switch was off. */
    private function mcpPayment(bool $upfront = false): array
    {
        $required = json_decode((string) $this->mcpSeller(upfront: $upfront)->process(self::call('wisdom'), $this->handler())->getBody(), true)['result']['structuredContent'];

        return (new EvmExact(new Signer(self::KEY)))->pay($required, $required['accepts'][0]);
    }

    /** A paid HTTP request, signed while the switch was off. */
    private function httpPaid(): ServerRequest
    {
        $unpaid = $this->httpSeller()->process(new ServerRequest('GET', 'https://example.test/paid'), $this->handler());
        $required = PaymentHeaders::decode($unpaid->getHeaderLine(PaymentHeaders::REQUIRED));
        $payment = (new EvmExact(new Signer(self::KEY)))->pay($required, $required['accepts'][0]);

        return (new ServerRequest('GET', 'https://example.test/paid'))->withHeader(PaymentHeaders::SIGNATURE, PaymentHeaders::encode($payment));
    }

    private static function body(ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true);
    }

    // The switch itself.

    public function testTheSwitchIsAFileSetAndClearedByHand(): void
    {
        self::assertFalse($this->stop->engaged());
        self::assertNull($this->stop->note());

        $this->stop->engage('suspected leak');
        self::assertTrue($this->stop->engaged());
        self::assertStringContainsString('suspected leak', (string) $this->stop->note());
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->flag)), -4));
        $this->stop->engage('again');
        self::assertStringContainsString('again', (string) $this->stop->note(), 'engaging twice rewrites our own file');

        $this->stop->release();
        self::assertFalse($this->stop->engaged());
        $this->stop->release();
        self::assertFalse($this->stop->engaged(), 'releasing what is not engaged is not an error');
    }

    public function testATouchedFileCountsWithoutAnyCode(): void
    {
        mkdir(dirname($this->flag), 0700, true);
        touch($this->flag);

        self::assertTrue($this->stop->engaged(), 'touch is enough in an emergency');
    }

    public function testARelativePathIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Stop('var/x402.stop');
    }

    public function testAnythingAtThePathCountsAsEngaged(): void
    {
        mkdir($this->flag, 0700, true);
        self::assertTrue($this->stop->engaged(), 'a directory made by mistake does not read as off');
        rmdir($this->flag);

        symlink($this->root.'/nowhere', $this->flag);
        self::assertTrue($this->stop->engaged(), 'a dangling link does not read as off');
    }

    public function testADirectoryThisProcessCannotSearchCountsAsEngaged(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('root can search any directory');
        }
        mkdir($this->root.'/locked', 0700, true);
        $stop = new Stop($this->root.'/locked/x402.stop');
        self::assertFalse($stop->engaged());
        chmod($this->root.'/locked', 0600);

        self::assertTrue($stop->engaged(), 'it could not see a flag there, so it does not say off');
    }

    public function testEngageRefusesALinkAndLeavesItsTargetAlone(): void
    {
        mkdir(dirname($this->flag), 0700, true);
        file_put_contents($this->root.'/precious', 'keep');
        symlink($this->root.'/precious', $this->flag);

        try {
            $this->stop->engage('x');
            self::fail('a link must be refused');
        } catch (\RuntimeException) {
            self::assertSame('keep', file_get_contents($this->root.'/precious'));
        }
    }

    public function testEngageRefusesADirectoryOthersCanWriteTo(): void
    {
        mkdir(dirname($this->flag), 0700, true);
        chmod(dirname($this->flag), 0777);

        $this->expectException(\RuntimeException::class);
        $this->stop->engage();
    }

    // The seller.

    public function testAPausedMcpSellerAnswersAnUnpaidCallWithThePauseNotAPrice(): void
    {
        $this->stop->engage();
        $body = self::body($this->mcpSeller()->process(self::call('wisdom'), $this->handler()));

        self::assertSame(-32000, $body['error']['code']);
        self::assertSame(Stop::REASON, $body['error']['data']['reason']);
        self::assertSame(2, $body['id'], 'the buyer can match the answer to its call');
        self::assertSame(0, $this->runs);
    }

    public function testAPaidCallToAPausedMcpSellerIsNeitherVerifiedClaimedRunNorSettled(): void
    {
        $payment = $this->mcpPayment();
        $this->stop->engage();
        $seller = $this->mcpSeller();
        [$verifies, $claims] = [$this->verifies, $this->claims];
        $body = self::body($seller->process(self::call('wisdom', $payment), $this->handler()));

        self::assertSame(Stop::REASON, $body['error']['data']['reason']);
        self::assertSame([$verifies, $claims], [$this->verifies, $this->claims], 'not verified, not claimed');
        self::assertSame([0, 0], [$this->runs, $this->settles]);
    }

    public function testAPaidRequestToAPausedHttpSellerIsNeitherVerifiedClaimedRunNorSettled(): void
    {
        $paid = $this->httpPaid();
        $this->stop->engage();
        $response = $this->httpSeller()->process($paid, $this->handler());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(Stop::REASON, self::body($response)['error']);
        self::assertNotSame('', $response->getHeaderLine('Retry-After'));
        self::assertSame([0, 0, 0], [$this->runs, $this->settles, $this->verifies]);
    }

    public function testAFreeToolStillRunsWhilePaused(): void
    {
        $this->stop->engage();
        $this->mcpSeller()->process(self::call('free_tool'), $this->handler());

        self::assertSame(1, $this->runs, 'the switch stops payments, not the server');
    }

    public function testTheSellerAsksAgainOnEveryRequest(): void
    {
        $seller = $this->mcpSeller();
        $this->stop->engage();
        $seller->process(self::call('wisdom'), $this->handler());
        $this->stop->release();
        $result = self::body($seller->process(self::call('wisdom'), $this->handler()))['result'];

        self::assertTrue($result['isError'], 'released: the normal PaymentRequired is back');
        self::assertSame(2, $result['structuredContent']['x402Version']);
    }

    public function testAPaymentAlreadyDeliveredIsReplayedWhilePaused(): void
    {
        $payment = $this->mcpPayment();
        $seller = $this->mcpSeller(ledger: true);
        $first = self::body($seller->process(self::call('wisdom', $payment), $this->handler()))['result'];
        self::assertSame('ran', $first['content'][0]['text']);
        self::assertSame([1, 1], [$this->runs, $this->settles]);

        $this->stop->engage();
        $again = self::body($seller->process(self::call('wisdom', $payment), $this->handler()));

        self::assertSame('ran', $again['result']['content'][0]['text'], 'paid for, so given back');
        self::assertSame([1, 1], [$this->runs, $this->settles], 'not run again, not charged again');
    }

    public function testAnUpfrontToolThatStillOwesItsAnswerRunsWhilePaused(): void
    {
        $payment = $this->mcpPayment(upfront: true);
        $seller = $this->mcpSeller(ledger: true, upfront: true);
        $this->tool = static fn (): array => ['isError' => true, 'content' => [['type' => 'text', 'text' => 'broke']]];
        $seller->process(self::call('wisdom', $payment), $this->handler());
        self::assertSame([1, 1], [$this->runs, $this->settles], 'charged first, then the tool failed');

        $this->tool = static fn (): array => ['content' => [['type' => 'text', 'text' => 'fixed']]];
        $this->stop->engage();
        $again = self::body($seller->process(self::call('wisdom', $payment), $this->handler()));

        self::assertSame('fixed', $again['result']['content'][0]['text'], 'the buyer already paid: it gets what it paid for');
        self::assertSame([2, 1], [$this->runs, $this->settles], 'no second charge');
    }

    public function testSwitchedOnWhileTheToolRunsNothingIsSettledAndTheAnswerIsWithheld(): void
    {
        $payment = $this->mcpPayment();
        $this->tool = function (): array {
            $this->stop->engage();

            return ['content' => [['type' => 'text', 'text' => 'ran']]];
        };
        $body = self::body($this->mcpSeller()->process(self::call('wisdom', $payment), $this->handler()));

        self::assertSame(0, $this->settles, 'the facilitator is not asked');
        self::assertStringNotContainsString('"ran"', json_encode($body), 'the answer is not given away unpaid');
    }

    public function testSwitchedOnWhileAnHttpHandlerRunsNothingIsSettled(): void
    {
        $paid = $this->httpPaid();
        $this->tool = function (): array {
            $this->stop->engage();

            return ['ok' => true];
        };
        $response = $this->httpSeller()->process($paid, $this->handler());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(0, $this->settles);
    }

    // The buyer.

    /** A seller that always asks 402; counts what reaches it. */
    private function asking(bool $mcp, int &$sent): ClientInterface
    {
        $required = ['x402Version' => 2, 'accepts' => [Networks::requirements('eip155:84532', '0.01', self::PAY_TO)], 'resource' => ['url' => 'https://example.test/x']];

        return new class($required, $mcp, $sent) implements ClientInterface {
            public function __construct(private array $required, private bool $mcp, private int &$sent)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->sent;
                if ($this->mcp) {
                    return new Response(200, ['Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 7, 'result' => ['isError' => true, 'structuredContent' => $this->required, 'content' => [['type' => 'text', 'text' => json_encode($this->required)]]]]));
                }

                return new Response(402, [PaymentHeaders::REQUIRED => PaymentHeaders::encode($this->required)]);
            }
        };
    }

    private static function toolCall(): Request
    {
        return new Request('POST', 'https://example.test/mcp', ['Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'wisdom', 'arguments' => []]]));
    }

    /** A dispatcher that records what it saw, and runs $onCheck when a payee is screened. */
    private function events(?\Closure $onCheck = null): object
    {
        return new class($onCheck) implements EventDispatcherInterface {
            /** @var list<object> */
            public array $seen = [];

            public function __construct(private ?\Closure $onCheck)
            {
            }

            public function dispatch(object $event): object
            {
                $this->seen[] = $event;
                if ($event instanceof AddressCheck) {
                    $this->onCheck?->__invoke();
                }

                return $event;
            }
        };
    }

    public function testAPausedMcpBuyerSignsNothingAndSpendsNothing(): void
    {
        $budget = Budget::usd('eip155:84532', '0.05');
        $sent = 0;
        $refused = [];
        $events = $this->events();
        $client = new PayingMcpClient($this->asking(true, $sent), [new EvmExact(new Signer(self::KEY))], $budget, new Psr17Factory(), null, $events, function (array $req, string $reason) use (&$refused) {
            $refused[] = $reason;
        }, stop: $this->stop);
        $this->stop->engage();
        $response = $client->sendRequest(self::toolCall());

        self::assertSame(1, $sent, 'only the unpaid call went out');
        self::assertTrue(self::body($response)['result']['isError'], 'the caller sees the PaymentRequired');
        self::assertSame('0', $budget->spent('eip155:84532'));
        self::assertSame([Stop::REASON], $refused);
        $outcomes = array_values(array_filter($events->seen, static fn (object $e) => $e instanceof PurchaseOutcome));
        self::assertCount(1, $outcomes);
        self::assertSame(PurchaseOutcome::REFUSED, $outcomes[0]->status);
        self::assertSame(Stop::REASON, $outcomes[0]->reason);
        self::assertSame('wisdom', $outcomes[0]->tool);
    }

    public function testAPausedHttpBuyerSignsNothingAndSpendsNothing(): void
    {
        $budget = Budget::usd('eip155:84532', '0.05');
        $sent = 0;
        $client = new PayingHttpClient($this->asking(false, $sent), [new EvmExact(new Signer(self::KEY))], $budget, stop: $this->stop);
        $this->stop->engage();
        $response = $client->sendRequest(new Request('GET', 'https://example.test/x'));

        self::assertSame(402, $response->getStatusCode());
        self::assertSame(1, $sent);
        self::assertSame('0', $budget->spent('eip155:84532'));
    }

    public function testSwitchedOnWhileTheMcpBuyerScreensThePayeeNothingIsSigned(): void
    {
        $budget = Budget::usd('eip155:84532', '0.05');
        $sent = 0;
        // The payee screening (a network call in real life) is where time passes.
        $events = $this->events(fn () => $this->stop->engage());
        $client = new PayingMcpClient($this->asking(true, $sent), [new EvmExact(new Signer(self::KEY))], $budget, new Psr17Factory(), null, $events, stop: $this->stop);
        $response = $client->sendRequest(self::toolCall());

        self::assertSame(1, $sent, 'nothing paid went out');
        self::assertTrue(self::body($response)['result']['isError']);
        self::assertSame('0', $budget->spent('eip155:84532'), 'the reservation was given back');
    }

    public function testSwitchedOnWhileTheHttpBuyerScreensThePayeeNothingIsSigned(): void
    {
        $budget = Budget::usd('eip155:84532', '0.05');
        $sent = 0;
        $events = $this->events(fn () => $this->stop->engage());
        $client = new PayingHttpClient($this->asking(false, $sent), [new EvmExact(new Signer(self::KEY))], $budget, null, $events, stop: $this->stop);
        $response = $client->sendRequest(new Request('GET', 'https://example.test/x'));

        self::assertSame(402, $response->getStatusCode());
        self::assertSame(1, $sent);
        self::assertSame('0', $budget->spent('eip155:84532'));
    }

    public function testWithoutASwitchNothingChanges(): void
    {
        $budget = Budget::usd('eip155:84532', '0.05');
        $sent = 0;
        $client = new PayingHttpClient($this->asking(false, $sent), [new EvmExact(new Signer(self::KEY))], $budget);
        $client->sendRequest(new Request('GET', 'https://example.test/x'));

        self::assertSame(2, $sent, 'it signs and retries, as before');
    }
}
