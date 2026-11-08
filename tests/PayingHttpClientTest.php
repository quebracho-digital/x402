<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\Budget;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileClaims;
use Quebracho\X402\Networks;
use Quebracho\X402\PayingHttpClient;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Scheme;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\X402Middleware;

/**
 * Buyer and seller from this library talking to each other, no network.
 */
final class PayingHttpClientTest extends TestCase
{
    use TempClaims;

    public int $served = 0;

    private function seller(): ClientInterface
    {
        $f = new Psr17Factory();
        $req = Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
        $middleware = new X402Middleware([new EvmExact()], fn () => [$req], (new FileClaims($this->claimsDir()))->__invoke(...), $f, $f, Settlement::mock());
        $test = $this;
        $handler = new class($test, $f) implements RequestHandlerInterface {
            public function __construct(private PayingHttpClientTest $test, private Psr17Factory $f)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->test->served;

                return $this->f->createResponse(200)->withBody($this->f->createStream('wisdom: '.$request->getBody()));
            }
        };

        return new class($middleware, $handler) implements ClientInterface {
            public function __construct(private X402Middleware $middleware, private RequestHandlerInterface $handler)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $server = new ServerRequest($request->getMethod(), $request->getUri(), $request->getHeaders(), (string) $request->getBody());

                return $this->middleware->process($server, $this->handler);
            }
        };
    }

    private function buyer(Budget $budget): PayingHttpClient
    {
        $key = new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318');

        return new PayingHttpClient($this->seller(), [new EvmExact($key)], $budget);
    }

    public function testPaysAndRetriesWithTheSameBody(): void
    {
        $budget = Budget::usd('eip155:84532', '0.05');
        $response = $this->buyer($budget)->sendRequest(new Request('POST', 'https://example.test/mcp', [], 'please'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('wisdom: please', (string) $response->getBody());
        self::assertSame('10000', $budget->spent('eip155:84532'));
    }

    public function testStopsAtTheCap(): void
    {
        $budget = Budget::usd('eip155:84532', '0.02');
        $client = $this->buyer($budget);
        $statuses = array_map(fn () => $client->sendRequest(new Request('POST', 'https://example.test/mcp'))->getStatusCode(), range(1, 3));

        self::assertSame([200, 200, 402], $statuses);
        self::assertSame(2, $this->served);
        self::assertSame('20000', $budget->spent('eip155:84532'));
    }

    /**
     * A seller that quotes a 402 once, then answers the paid retry with $paid.
     */
    private function quoting(\Closure $paid): ClientInterface
    {
        $f = new Psr17Factory();
        $header = PaymentHeaders::encode(['x402Version' => 2, 'resource' => ['url' => 'https://example.test/mcp'], 'accepts' => [Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD')]]);
        $quote = $f->createResponse(402)->withHeader(PaymentHeaders::REQUIRED, $header);

        return new class($quote, $paid) implements ClientInterface {
            private bool $asked = false;

            public function __construct(private ResponseInterface $quote, private \Closure $paid)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if (!$this->asked) {
                    $this->asked = true;

                    return $this->quote;
                }

                return ($this->paid)($request);
            }
        };
    }

    private function key(): Signer
    {
        return new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318');
    }

    public function testAPayThatThrowsSpendsNothing(): void
    {
        $failing = new class implements Scheme {
            public function name(): string
            {
                return 'exact';
            }

            public function supports(string $network): bool
            {
                return true;
            }

            public function pay(array $required, array $req): array
            {
                throw new \RuntimeException('no key');
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
        $budget = Budget::usd('eip155:84532', '0.05');
        $client = new PayingHttpClient($this->quoting(fn () => new Response(200)), [$failing], $budget);

        try {
            $client->sendRequest(new Request('POST', 'https://example.test/mcp'));
            self::fail('the pay error must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('no key', $e->getMessage());
        }
        self::assertSame('0', $budget->spent('eip155:84532'));
    }

    public function testAnOnPaidThatThrowsKeepsThePaidAnswer(): void
    {
        $client = new PayingHttpClient($this->seller(), [new EvmExact($this->key())], Budget::usd('eip155:84532', '0.05'), onPaid: fn () => throw new \RuntimeException('log down'));
        $response = $client->sendRequest(new Request('POST', 'https://example.test/mcp', [], 'please'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('wisdom: please', (string) $response->getBody());
    }

    public function testAnOnRefusedThatThrowsKeepsTheQuote(): void
    {
        // Unpayable on purpose (no cap on this network): onRefused runs, and its error must not escape.
        $client = new PayingHttpClient($this->seller(), [new EvmExact($this->key())], Budget::usd('eip155:11155111', '1'), onRefused: fn () => throw new \RuntimeException('log down'));
        self::assertSame(402, $client->sendRequest(new Request('POST', 'https://example.test/mcp'))->getStatusCode());
    }

    public function testAPaidRetryThatThrowsLeavesTheSignatureUnsettled(): void
    {
        $now = time();
        // The chain says the nonce was never used: once the signature expires, its amount is free again.
        $budget = new Budget(['eip155:84532' => '50000'], used: fn () => false, clock: fn () => $now + 3600);
        $client = new PayingHttpClient($this->quoting(fn () => throw new \RuntimeException('connection reset')), [new EvmExact($this->key())], $budget);

        try {
            $client->sendRequest(new Request('POST', 'https://example.test/mcp'));
            self::fail('the transport error must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('connection reset', $e->getMessage());
        }
        self::assertSame('10000', $budget->spent('eip155:84532'));
        $budget->reclaim();
        self::assertSame('0', $budget->spent('eip155:84532'));
    }

    public function testAPaymentThatCannotBeSentGivesTheReservationBack(): void
    {
        // Signs, but into something no header can carry: it never leaves this process.
        $unsendable = new class implements Scheme {
            public function name(): string
            {
                return 'exact';
            }

            public function supports(string $network): bool
            {
                return true;
            }

            public function pay(array $required, array $req): array
            {
                return ['payload' => "\xff"];
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
        $budget = Budget::usd('eip155:84532', '0.05');
        $sent = 0;
        $send = function () use (&$sent): Response {
            ++$sent;

            return new Response(200);
        };
        $client = new PayingHttpClient($this->quoting($send), [$unsendable], $budget);

        try {
            $client->sendRequest(new Request('POST', 'https://example.test/mcp'));
            self::fail('the encoding error must reach the caller');
        } catch (\JsonException) {
        }
        self::assertSame(0, $sent);
        self::assertSame('0', $budget->spent('eip155:84532'));
    }

    /**
     * Five agents in one process (Fibers, as ReactPHP or Amp run them) share a
     * 0.02 budget and each pays 0.01. pay() suspends, as a scheme that fetches a
     * blockhash or a block height through a Fiber-aware client does: every
     * payment is in flight before any finishes. Only two may be signed and sent.
     */
    public function testConcurrentPaymentsNeverPassTheCap(): void
    {
        $evm = new EvmExact($this->key());
        $scheme = new class($evm) implements Scheme {
            public function name(): string
            {
                return 'exact';
            }

            public int $signed = 0;

            public function __construct(private EvmExact $evm)
            {
            }

            public function supports(string $network): bool
            {
                return $this->evm->supports($network);
            }

            public function pay(array $required, array $req): array
            {
                \Fiber::suspend();
                ++$this->signed;

                return $this->evm->pay($required, $req);
            }

            public function verify(array $payment, array $req): ?string
            {
                return $this->evm->verify($payment, $req);
            }

            public function payer(array $payment): ?string
            {
                return $this->evm->payer($payment);
            }
        };
        $f = new Psr17Factory();
        $quote = $f->createResponse(402)->withHeader(PaymentHeaders::REQUIRED, PaymentHeaders::encode(['x402Version' => 2, 'resource' => ['url' => 'https://example.test/mcp'], 'accepts' => [Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD')]]));
        $paid = $f->createResponse(200)->withHeader(PaymentHeaders::RESPONSE, PaymentHeaders::encode(['success' => true]));
        $seller = new class($quote, $paid) implements ClientInterface {
            public int $sent = 0;

            public function __construct(private ResponseInterface $quote, private ResponseInterface $paid)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                if (!$request->hasHeader(PaymentHeaders::SIGNATURE)) {
                    return $this->quote;
                }
                ++$this->sent;

                return $this->paid;
            }
        };
        $budget = Budget::usd('eip155:84532', '0.02');
        $client = new PayingHttpClient($seller, [$scheme], $budget);

        $fibers = array_map(fn () => new \Fiber(fn () => $client->sendRequest(new Request('POST', 'https://example.test/mcp'))->getStatusCode()), range(1, 5));
        foreach ($fibers as $fiber) {
            $fiber->start();
        }
        foreach ($fibers as $fiber) {
            if ($fiber->isSuspended()) {
                $fiber->resume();
            }
        }
        $statuses = array_map(fn (\Fiber $fiber) => $fiber->getReturn(), $fibers);
        sort($statuses);

        self::assertSame(2, $scheme->signed, 'signed no more than the cap holds');
        self::assertSame(2, $seller->sent, 'sent exactly the payments that fit');
        self::assertSame([200, 200, 402, 402, 402], $statuses);
        self::assertSame('20000', $budget->spent('eip155:84532'));
    }

    public function testNeverPaysOnANetworkWithoutACap(): void
    {
        $response = $this->buyer(Budget::usd('eip155:11155111', '1'))->sendRequest(new Request('POST', 'https://example.test/mcp'));
        self::assertSame(402, $response->getStatusCode());
        self::assertSame(0, $this->served);
    }
}
