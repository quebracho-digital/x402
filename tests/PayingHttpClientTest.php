<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
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
use Quebracho\X402\Signer;
use Quebracho\X402\X402Middleware;

/**
 * Buyer and seller from this library talking to each other, no network.
 */
final class PayingHttpClientTest extends TestCase
{
    public int $served = 0;

    private function seller(): ClientInterface
    {
        $f = new Psr17Factory();
        $req = Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
        $middleware = new X402Middleware([new EvmExact()], fn () => [$req], (new FileClaims(sys_get_temp_dir().'/x402-claims-'.bin2hex(random_bytes(8))))->__invoke(...), $f, $f);
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

    public function testNeverPaysOnANetworkWithoutACap(): void
    {
        $response = $this->buyer(Budget::usd('eip155:11155111', '1'))->sendRequest(new Request('POST', 'https://example.test/mcp'));
        self::assertSame(402, $response->getStatusCode());
        self::assertSame(0, $this->served);
    }
}
