<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\Settlement;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\Budget;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileClaims;
use Quebracho\X402\Networks;
use Quebracho\X402\PayingMcpClient;
use Quebracho\X402\Signer;
use Quebracho\X402\X402McpMiddleware;

/**
 * The MCP buyer against this library's MCP seller, in band, no network.
 */
final class PayingMcpClientTest extends TestCase
{
    public int $served = 0;
    private array $paid = [];

    private function seller(?\Closure $settle = null, bool $sse = false, ?EventDispatcherInterface $events = null): ClientInterface
    {
        $f = new Psr17Factory();
        $req = Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
        $middleware = new X402McpMiddleware([new EvmExact()], fn (array $p) => 'wisdom' === $p['name'] ? [$req] : null, (new FileClaims(sys_get_temp_dir().'/x402-claims-'.bin2hex(random_bytes(8))))->__invoke(...), $f, $f, $settle ?? Settlement::mock(), $events);
        $handler = new class($this, $f, $sse) implements RequestHandlerInterface {
            public function __construct(private PayingMcpClientTest $test, private Psr17Factory $f, private bool $sse)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->test->served;
                $json = json_encode(['jsonrpc' => '2.0', 'id' => 7, 'result' => ['content' => [['type' => 'text', 'text' => 'paid content']]]]);

                return $this->sse
                    ? $this->f->createResponse(200)->withHeader('Content-Type', 'text/event-stream')->withBody($this->f->createStream("event: message\ndata: $json\n\n"))
                    : $this->f->createResponse(200)->withHeader('Content-Type', 'application/json')->withBody($this->f->createStream($json));
            }
        };

        return new class($middleware, $handler) implements ClientInterface {
            public function __construct(private X402McpMiddleware $middleware, private RequestHandlerInterface $handler)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->middleware->process(new ServerRequest('POST', $request->getUri(), $request->getHeaders(), (string) $request->getBody()), $this->handler);
            }
        };
    }

    private function buyer(Budget $budget, ?\Closure $settle = null, bool $sse = false, ?EventDispatcherInterface $sellerEvents = null, ?EventDispatcherInterface $buyerEvents = null): PayingMcpClient
    {
        $key = new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318');

        return new PayingMcpClient($this->seller($settle, $sse, $sellerEvents), [new EvmExact($key)], $budget, new Psr17Factory(), function (string $tool, array $req, array $receipt) {
            $this->paid[] = [$tool, $req['amount'], $receipt['success']];
        }, $buyerEvents);
    }

    private static function call(string $tool = 'wisdom'): Request
    {
        return new Request('POST', 'https://example.test/mcp', ['Content-Type' => 'application/json'], json_encode(['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => []]]));
    }

    private static function toolResult(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if (preg_match('/^data: (.*)$/m', $body, $m)) {
            $body = $m[1];
        }

        return json_decode($body, true)['result'];
    }

    public function testPaysInBandAndReadsTheReceipt(): void
    {
        foreach ([false, true] as $sse) {
            $budget = Budget::usd('eip155:84532', '0.05');
            $result = self::toolResult($this->buyer($budget, sse: $sse)->sendRequest(self::call()));

            self::assertSame('paid content', $result['content'][0]['text']);
            self::assertTrue($result['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
            self::assertSame('10000', $budget->spent('eip155:84532'));
        }
        self::assertSame([['wisdom', '10000', true], ['wisdom', '10000', true]], $this->paid);
    }

    public function testStopsAtTheCap(): void
    {
        $budget = Budget::usd('eip155:84532', '0.02');
        $client = $this->buyer($budget);
        $errors = array_map(fn () => !empty(self::toolResult($client->sendRequest(self::call()))['isError']), range(1, 3));

        self::assertSame([false, false, true], $errors);
        self::assertSame(2, $this->served);
        self::assertSame('20000', $budget->spent('eip155:84532'));
    }

    public function testAFailedSettlementStillCountsAgainstTheCap(): void
    {
        $budget = Budget::usd('eip155:84532', '0.05');
        $result = self::toolResult($this->buyer($budget, fn () => ['success' => false, 'errorReason' => 'insufficient_funds'])->sendRequest(self::call()));

        self::assertTrue($result['isError']);
        self::assertSame('insufficient_funds', $result['structuredContent']['error']);
        // Signed is committed: the seller holds an authorization it can settle.
        self::assertSame('10000', $budget->spent('eip155:84532'));
        self::assertSame([], $this->paid);
    }

    public function testASellerThatNeverConfirmsCannotDrainPastTheCap(): void
    {
        $budget = Budget::usd('eip155:84532', '0.05');
        $buyer = $this->buyer($budget, fn () => ['success' => false, 'errorReason' => 'whatever']);
        $errors = array_map(fn () => self::toolResult($buyer->sendRequest(self::call()))['structuredContent']['error'], range(1, 8));

        self::assertSame(array_merge(array_fill(0, 5, 'whatever'), array_fill(0, 3, 'Payment required to access this resource')), $errors);
        self::assertSame('50000', $budget->spent('eip155:84532'));
    }

    public function testFreeToolsAndOtherMethodsPassThrough(): void
    {
        $budget = Budget::usd('eip155:84532', '0.05');
        $result = self::toolResult($this->buyer($budget)->sendRequest(self::call('echo')));

        self::assertSame('paid content', $result['content'][0]['text']);
        self::assertSame('0', $budget->spent('eip155:84532'));
        self::assertSame(1, $this->served);
    }

    public function testNeverPaysOnANetworkWithoutACap(): void
    {
        $result = self::toolResult($this->buyer(Budget::usd('eip155:11155111', '1'))->sendRequest(self::call()));
        self::assertTrue($result['isError']);
        self::assertSame(0, $this->served);
    }

    /** A PSR-14 dispatcher that rejects one address, and records what it saw. */
    private static function denying(string $address, ?array &$seen = []): EventDispatcherInterface
    {
        return new class($address, $seen) implements EventDispatcherInterface {
            public function __construct(private string $address, private ?array &$seen)
            {
            }

            public function dispatch(object $event): object
            {
                if (!$event instanceof AddressCheck) {
                    return $event;
                }
                $this->seen[] = [$event->role, $event->address];
                if (0 === strcasecmp($event->address, $this->address)) {
                    $event->reject('address_denied');
                }

                return $event;
            }
        };
    }

    public function testTheSellerRefusesADeniedPayerBeforeRunningOrClaiming(): void
    {
        $payer = (new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318'))->address();
        $budget = Budget::usd('eip155:84532', '0.05');
        $result = self::toolResult($this->buyer($budget, sellerEvents: self::denying($payer, $seen))->sendRequest(self::call()));

        self::assertSame('address_denied', $result['structuredContent']['error']);
        self::assertSame([[AddressCheck::PAYER, $payer]], $seen);
        self::assertSame(0, $this->served);
        // Signed, so spent: the buyer cannot know the seller will not settle it.
        self::assertSame('10000', $budget->spent('eip155:84532'));
    }

    public function testTheBuyerNeverSignsForADeniedPayTo(): void
    {
        $budget = Budget::usd('eip155:84532', '0.05');
        $result = self::toolResult($this->buyer($budget, buyerEvents: self::denying('0x000000000000000000000000000000000000dEaD', $seen))->sendRequest(self::call()));

        self::assertSame('Payment required to access this resource', $result['structuredContent']['error']);
        self::assertSame([[AddressCheck::PAY_TO, '0x000000000000000000000000000000000000dEaD']], $seen);
        self::assertSame('0', $budget->spent('eip155:84532'));
        self::assertSame(0, $this->served);
    }

    public function testAListenerThatThrowsBlocksThePayment(): void
    {
        $throwing = new class implements EventDispatcherInterface {
            public function dispatch(object $event): object
            {
                if (!$event instanceof AddressCheck) {
                    return $event;
                }
                throw new \RuntimeException('screening API down');
            }
        };
        $result = self::toolResult($this->buyer(Budget::usd('eip155:84532', '0.05'), sellerEvents: $throwing)->sendRequest(self::call()));

        self::assertSame('address_check_failed', $result['structuredContent']['error']);
        self::assertSame(0, $this->served);
    }

    public function testADeferredRejectionWithholdsTheOutputItRanFor(): void
    {
        $lookups = 0;
        $deferring = new class($lookups) implements EventDispatcherInterface {
            public function __construct(private int &$lookups)
            {
            }

            public function dispatch(object $event): object
            {
                if (!$event instanceof AddressCheck) {
                    return $event;
                }
                // Started now, answered when the seller asks before settling.
                $event->defer(function () {
                    ++$this->lookups;

                    return 'address_sanctioned';
                });

                return $event;
            }
        };
        $result = self::toolResult($this->buyer(Budget::usd('eip155:84532', '0.05'), sellerEvents: $deferring)->sendRequest(self::call()));

        self::assertSame('address_sanctioned', $result['structuredContent']['error']);
        self::assertSame(1, $this->served);
        self::assertSame(1, $lookups);
        self::assertSame([], $this->paid);
    }
}
