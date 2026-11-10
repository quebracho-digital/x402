<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\Budget;
use Quebracho\X402\Buyer;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileClaims;
use Quebracho\X402\FileLedger;
use Quebracho\X402\MockFacilitator;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\PriceList;
use Quebracho\X402\Seller;
use Quebracho\X402\Signer;
use Quebracho\X402\Stop;
use Quebracho\X402\X402McpMiddleware;
use Quebracho\X402\X402Middleware;

/**
 * Seller and Buyer: the middlewares and clients, built by name. A buyer built by one pays a seller built by
 * the other, end to end.
 */
final class BuildersTest extends TestCase
{
    use TempClaims {
        tearDown as private removeClaims;
    }

    private ?string $ledgerDir = null;

    protected function tearDown(): void
    {
        if (null !== $this->ledgerDir) {
            exec('rm -rf '.escapeshellarg($this->ledgerDir));
        }
        $this->removeClaims();
    }

    private const PAY_TO = '0x000000000000000000000000000000000000dEaD';
    private const KEY = '0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318';

    private function seller(): Seller
    {
        $f = new Psr17Factory();

        return Seller::with([new EvmExact()], new MockFacilitator(), $f, $f);
    }

    public function testASellerNeedsALedgerOrClaims(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ledger() or claims()');
        $this->seller()->http(static fn () => null);
    }

    public function testEachOptionReturnsACopy(): void
    {
        $bare = $this->seller();
        $with = $bare->claims(new FileClaims($this->claimsDir()));

        self::assertNotSame($bare, $with);
        self::assertInstanceOf(X402Middleware::class, $with->http(static fn () => null));
        $this->expectException(\LogicException::class);
        $bare->mcp(static fn () => null);
    }

    public function testABuyerPaysASellerEndToEnd(): void
    {
        $req = Networks::requirements('eip155:84532', '0.01', self::PAY_TO);
        $this->ledgerDir = sys_get_temp_dir().'/x402-builders-'.bin2hex(random_bytes(4));
        $middleware = $this->seller()->ledger(new FileLedger($this->ledgerDir))->http(static fn () => [$req]);
        $runs = 0;
        // The seller, as the buyer's HTTP client sees it: every request goes through the middleware.
        $server = new class($middleware, $runs) implements ClientInterface {
            public function __construct(private readonly X402Middleware $middleware, private int &$runs)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $handler = new class($this->runs) implements RequestHandlerInterface {
                    public function __construct(private int &$runs)
                    {
                    }

                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        ++$this->runs;

                        return new Response(200, [], 'paid content');
                    }
                };

                return $this->middleware->process(new ServerRequest($request->getMethod(), $request->getUri(), $request->getHeaders()), $handler);
            }
        };
        $paid = [];
        $buyer = Buyer::with([new EvmExact(new Signer(self::KEY))], Budget::usd('eip155:84532', '0.05'))
            ->onPaid(static function (array $req, array $receipt) use (&$paid): void { $paid[] = $receipt; });

        $response = $buyer->http($server)->sendRequest((new Psr17Factory())->createRequest('GET', 'https://example.test/paid'));

        self::assertSame('paid content', (string) $response->getBody());
        self::assertSame(1, $runs);
        self::assertTrue($paid[0]['success']);
        self::assertTrue(PaymentHeaders::decode($response->getHeaderLine(PaymentHeaders::RESPONSE))['extensions']['mock']);
    }

    public function testAPriceListGivesTheMcpMiddlewareItsPriceAndItsDuration(): void
    {
        $prices = new PriceList('eip155:84532', self::PAY_TO, '0.01', ['slow'], timeouts: ['slow' => 120], durations: ['slow' => 100]);
        $mcp = $this->seller()->claims(new FileClaims($this->claimsDir()))->mcp($prices);
        $server = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"result":{"content":[]}}');
            }
        };
        $call = static fn (array $meta) => new ServerRequest('POST', 'https://example.test/mcp', [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'slow', 'arguments' => []] + $meta]));
        $required = json_decode((string) $mcp->process($call([]), $server)->getBody(), true)['result']['structuredContent'];
        self::assertSame(120, $required['accepts'][0]['maxTimeoutSeconds']);

        // Signed for 60 s only: the list's duration (100 s) refuses it before anything is claimed.
        $short = ['maxTimeoutSeconds' => 60] + $required['accepts'][0];
        $payment = (new EvmExact(new Signer(self::KEY)))->pay($required, $short);
        $payment['accepted'] = $required['accepts'][0];
        $out = json_decode((string) $mcp->process($call(['_meta' => [X402McpMiddleware::PAYMENT => $payment]]), $server)->getBody(), true);

        self::assertSame('invalid_exact_evm_payload_authorization_valid_before', $out['result']['structuredContent']['error']);
    }

    public function testAStoppedBuyerSignsNothing(): void
    {
        $stop = new Stop($this->claimsDir().'/stop');
        $stop->engage('test');
        $refused = [];
        $offer = ['x402Version' => 2, 'accepts' => [Networks::requirements('eip155:84532', '0.01', self::PAY_TO)]];
        $seller = new class($offer) implements ClientInterface {
            public int $asked = 0;

            public function __construct(private readonly array $offer)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->asked;

                return new Response(402, [PaymentHeaders::REQUIRED => PaymentHeaders::encode($this->offer)]);
            }
        };
        $buyer = Buyer::with([new EvmExact(new Signer(self::KEY))], Budget::usd('eip155:84532', '0.05'))
            ->stop($stop)
            ->onRefused(static function (array $req, string $reason) use (&$refused): void { $refused[] = $reason; });

        $response = $buyer->http($seller)->sendRequest((new Psr17Factory())->createRequest('GET', 'https://example.test/paid'));

        self::assertSame(402, $response->getStatusCode());
        self::assertSame(1, $seller->asked, 'no paid retry');
        self::assertSame([Stop::REASON], $refused);
    }
}
