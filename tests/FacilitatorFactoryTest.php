<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\CircleFacilitator;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Facilitator;
use Quebracho\X402\FacilitatorException;
use Quebracho\X402\Facilitators;
use Quebracho\X402\FileClaims;
use Quebracho\X402\HttpFacilitator;
use Quebracho\X402\MockFacilitator;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Signer;
use Quebracho\X402\X402McpMiddleware;
use Quebracho\X402\X402Middleware;

/**
 * A facilitator named by a setting (Facilitators::from), and given whole to the middlewares: it settles, and
 * it verifies online too.
 */
final class FacilitatorFactoryTest extends TestCase
{
    use TempClaims;

    private int $runs = 0;

    private static function http(): ClientInterface
    {
        return new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(500);
            }
        };
    }

    /** @return iterable<string, array{string, class-string<Facilitator>}> */
    public static function settings(): iterable
    {
        yield 'mock' => ['mock', MockFacilitator::class];
        yield 'mock, with spaces' => [' mock ', MockFacilitator::class];
        yield "Circle's URL" => [CircleFacilitator::URL, CircleFacilitator::class];
        yield "Circle's URL, other case, a slash, a query" => ['HTTPS://API.circle.com/v1/facilitator/x402/?x=1', CircleFacilitator::class];
        yield 'any other https URL' => ['https://x402.org/facilitator', HttpFacilitator::class];
    }

    /** @param class-string<Facilitator> $class */
    #[DataProvider('settings')]
    public function testASettingNamesItsFacilitator(string $setting, string $class): void
    {
        $f = new Psr17Factory();

        self::assertInstanceOf($class, Facilitators::from($setting, self::http(), $f, $f, ''));
    }

    public function testAnythingElseIsRefusedWithoutShowingASecret(): void
    {
        $f = new Psr17Factory();
        try {
            Facilitators::from('http://user:s3cret@facilitator.example/x?token=abc', self::http(), $f, $f);
            self::fail('an http URL is no facilitator');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('http://facilitator.example', $e->getMessage());
            self::assertStringNotContainsString('s3cret', $e->getMessage());
            self::assertStringNotContainsString('abc', $e->getMessage());
        }
        $this->expectExceptionMessage('(unparseable)');
        Facilitators::from('mok', self::http(), $f, $f);
    }

    /** A facilitator that answers what the test says, and counts what it was asked. */
    private function facilitator(array $verify = ['isValid' => true], ?\Throwable $down = null): Facilitator
    {
        return new class($verify, $down) implements Facilitator {
            public array $asked = [];

            public function __construct(private readonly array $verify, private readonly ?\Throwable $down)
            {
            }

            public function verify(array $payment, array $req): array
            {
                $this->asked[] = 'verify';

                return null === $this->down ? $this->verify : throw $this->down;
            }

            public function settle(array $payment, array $req): array
            {
                $this->asked[] = 'settle';

                return ['success' => true, 'transaction' => '0x'.str_repeat('ab', 32), 'network' => $req['network'], 'payer' => $payment['payload']['authorization']['from']];
            }
        };
    }

    private function middleware(\Closure|Facilitator $settle, ?\Closure $verify = null): X402Middleware
    {
        $req = Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
        $f = new Psr17Factory();

        return new X402Middleware([new EvmExact()], static fn () => [$req], (new FileClaims($this->claimsDir()))->__invoke(...), $f, $f, $settle, verify: $verify);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class($this->runs) implements RequestHandlerInterface {
            public function __construct(private int &$runs)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->runs;

                return new Response(200, [], 'paid content');
            }
        };
    }

    private function paid(X402Middleware $middleware): ResponseInterface
    {
        $first = $middleware->process(new ServerRequest('POST', 'https://example.test/paid'), $this->handler());
        $required = PaymentHeaders::decode($first->getHeaderLine(PaymentHeaders::REQUIRED));
        $payment = (new EvmExact(new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318')))->pay($required, $required['accepts'][0]);

        return $middleware->process(new ServerRequest('POST', 'https://example.test/paid', [PaymentHeaders::SIGNATURE => PaymentHeaders::encode($payment)]), $this->handler());
    }

    public function testAFacilitatorGivenWholeVerifiesThenSettles(): void
    {
        $facilitator = $this->facilitator();
        $response = $this->paid($this->middleware($facilitator));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['verify', 'settle'], $facilitator->asked, 'verified online before the handler ran, settled after');
        self::assertTrue(PaymentHeaders::decode($response->getHeaderLine(PaymentHeaders::RESPONSE))['success']);
        self::assertSame(1, $this->runs);
    }

    public function testAPaymentItsVerifyRefusesRunsNothing(): void
    {
        $facilitator = $this->facilitator(['isValid' => false, 'invalidReason' => 'insufficient_funds']);
        $response = $this->paid($this->middleware($facilitator));

        self::assertSame(402, $response->getStatusCode());
        self::assertSame('insufficient_funds', json_decode((string) $response->getBody(), true)['error']);
        self::assertSame(['verify'], $facilitator->asked);
        self::assertSame(0, $this->runs);
    }

    public function testARefusalWithoutAReasonStillRefuses(): void
    {
        $response = $this->paid($this->middleware($this->facilitator(['isValid' => false])));

        self::assertSame('invalid_payment', json_decode((string) $response->getBody(), true)['error']);
        self::assertSame(0, $this->runs);
    }

    public function testAFacilitatorThatCannotBeReachedAnswers502(): void
    {
        $response = $this->paid($this->middleware($this->facilitator(down: new FacilitatorException('down'))));

        self::assertSame(502, $response->getStatusCode());
        self::assertSame(0, $this->runs);
    }

    public function testTheCallersOwnVerifyTakesThePlaceOfTheFacilitators(): void
    {
        $facilitator = $this->facilitator(['isValid' => false]);
        $response = $this->paid($this->middleware($facilitator, static fn (): ?string => null));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['settle'], $facilitator->asked);
    }

    public function testTheMockFacilitatorSettlesNothingAndSaysSo(): void
    {
        $response = $this->paid($this->middleware(new MockFacilitator()));
        $receipt = PaymentHeaders::decode($response->getHeaderLine(PaymentHeaders::RESPONSE));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['', true], [$receipt['transaction'], $receipt['extensions']['mock']]);
    }

    public function testTheMcpMiddlewareTakesAFacilitatorToo(): void
    {
        $req = Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
        $f = new Psr17Factory();
        $facilitator = $this->facilitator();
        $mcp = new X402McpMiddleware([new EvmExact()], static fn () => [$req], (new FileClaims($this->claimsDir()))->__invoke(...), $f, $f, $facilitator);
        $server = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"result":{"content":[]}}');
            }
        };
        $call = static fn (array $meta) => new ServerRequest('POST', 'https://example.test/mcp', [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'wisdom', 'arguments' => []] + $meta]));
        $required = json_decode((string) $mcp->process($call([]), $server)->getBody(), true)['result']['structuredContent'];
        $payment = (new EvmExact(new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318')))->pay($required, $required['accepts'][0]);
        $out = json_decode((string) $mcp->process($call(['_meta' => [X402McpMiddleware::PAYMENT => $payment]]), $server)->getBody(), true);

        self::assertTrue($out['result']['_meta'][X402McpMiddleware::PAYMENT_RESPONSE]['success']);
        self::assertSame(['verify', 'settle'], $facilitator->asked);
    }
}
