<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\Settlement;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FacilitatorException;
use Quebracho\X402\FileClaims;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\PaymentOutcome;
use Quebracho\X402\Signer;
use Quebracho\X402\X402Middleware;

/**
 * The optional steps around a sale: online verify (G3), release (G8),
 * facilitator down (G5), upfront (G4), resource (G2), outcome (G10).
 */
final class SaleTest extends TestCase
{
    private int $runs = 0;
    /** @var list<PaymentOutcome> */
    private array $outcomes = [];
    private FileClaims $claims;
    private array $req;

    protected function setUp(): void
    {
        $this->claims = new FileClaims(sys_get_temp_dir().'/x402-claims-'.bin2hex(random_bytes(8)));
        $this->req = Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
    }

    private function middleware(?\Closure $settle = null, ?\Closure $verify = null, bool $upfront = false, ?\Closure $resource = null): X402Middleware
    {
        $f = new Psr17Factory();
        $req = $this->req;
        if ($upfront) {
            $req['extra']['paymentFlow'] = 'upfront';
        }
        $events = new class($this->outcomes) implements EventDispatcherInterface {
            public function __construct(private array &$outcomes)
            {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof PaymentOutcome) {
                    $this->outcomes[] = $event;
                }

                return $event;
            }
        };

        return new X402Middleware([new EvmExact()], fn () => [$req], $this->claims->__invoke(...), $f, $f, $settle ?? Settlement::mock(), $events, verify: $verify, release: $this->claims->release(...), resource: $resource);
    }

    private function handler(int $status = 200): RequestHandlerInterface
    {
        return new class($this->runs, $status) implements RequestHandlerInterface {
            public function __construct(private int &$runs, private int $status)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->runs;

                return new Response($this->status, [], 'paid content');
            }
        };
    }

    private function paid(bool $upfront = false): ServerRequest
    {
        $required = PaymentHeaders::decode($this->middleware(upfront: $upfront)->process(new ServerRequest('GET', 'https://shop.test/x'), $this->handler())->getHeaderLine(PaymentHeaders::REQUIRED));
        $payment = (new EvmExact(new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318')))->pay($required, $required['accepts'][0]);

        return new ServerRequest('GET', 'https://shop.test/x', [PaymentHeaders::SIGNATURE => PaymentHeaders::encode($payment)]);
    }

    private static function tx(): array
    {
        return ['success' => true, 'transaction' => '0x'.str_repeat('ab', 32), 'network' => 'eip155:84532'];
    }

    public function testOnlineVerifyRefusalRunsNothingAndTakesNoClaim(): void
    {
        $request = $this->paid();
        $refused = $this->middleware(verify: fn () => 'insufficient_funds')->process($request, $this->handler());
        $this->assertSame([402, 0], [$refused->getStatusCode(), $this->runs]);
        $this->assertSame('rejected', $this->outcomes[0]->status);
        // The claim was never taken: the same payment, once funded, still buys.
        $this->assertSame(200, $this->middleware(fn () => self::tx())->process($request, $this->handler())->getStatusCode());
    }

    public function testNothingChargedFreesTheClaim(): void
    {
        $request = $this->paid();
        $this->assertSame(500, $this->middleware()->process($request, $this->handler(500))->getStatusCode());
        $this->assertSame('failed', $this->outcomes[0]->status);
        $this->middleware(fn () => ['success' => false, 'errorReason' => 'insufficient_funds'])->process($request, $this->handler());
        $settled = $this->middleware(fn () => self::tx())->process($request, $this->handler());
        $this->assertSame(200, $settled->getStatusCode());
        $this->assertSame('settled', end($this->outcomes)->status);
    }

    public function testAPendingSettleKeepsTheClaim(): void
    {
        $request = $this->paid();
        $this->middleware(fn () => ['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => '0x'.str_repeat('cd', 32)])->process($request, $this->handler());
        $again = $this->middleware(fn () => self::tx())->process($request, $this->handler());
        $this->assertSame('duplicate_settlement', json_decode((string) $again->getBody(), true)['error']);
    }

    public function testAnUnexpectedSettleErrorKeepsTheClaim(): void
    {
        $request = $this->paid();
        $this->middleware(fn () => throw new \TypeError('a third-party facilitator broke'))->process($request, $this->handler());
        $again = $this->middleware(fn () => self::tx())->process($request, $this->handler());
        $this->assertSame('duplicate_settlement', json_decode((string) $again->getBody(), true)['error']);
    }

    public function testASuccessThatDoesNotAddUpKeepsTheClaim(): void
    {
        $request = $this->paid();
        $this->middleware(fn () => ['success' => false, 'errorReason' => 'invalid_settle_response'])->process($request, $this->handler());
        $again = $this->middleware(fn () => self::tx())->process($request, $this->handler());
        $this->assertSame('duplicate_settlement', json_decode((string) $again->getBody(), true)['error']);
    }

    public function testUpfrontRecordsAHandlerThatThrowsAfterSettling(): void
    {
        $throws = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('tool broke');
            }
        };
        try {
            $this->middleware(fn () => self::tx(), upfront: true)->process($this->paid(true), $throws);
            $this->fail('the exception goes on');
        } catch (\RuntimeException) {
        }
        $this->assertSame('failed_after_settle', end($this->outcomes)->status);
    }

    public function testFacilitatorDownIs502(): void
    {
        $down = fn () => throw new FacilitatorException('timeout');
        // Down on verify: nothing claimed, the payment still buys.
        $request = $this->paid();
        $this->assertSame(502, $this->middleware(verify: $down)->process($request, $this->handler())->getStatusCode());
        $this->assertSame(200, $this->middleware(fn () => self::tx())->process($request, $this->handler())->getStatusCode());
        // Down on settle: it may have sent the transaction, so the claim stays.
        $request = $this->paid();
        $this->assertSame(502, $this->middleware($down)->process($request, $this->handler())->getStatusCode());
        $again = $this->middleware(fn () => self::tx())->process($request, $this->handler());
        $this->assertSame('duplicate_settlement', json_decode((string) $again->getBody(), true)['error']);
    }

    public function testUpfrontSettlesFirstAndKeepsTheReceiptWhateverTheHandlerSays(): void
    {
        $refused = $this->middleware(fn () => ['success' => false, 'errorReason' => 'insufficient_funds'], upfront: true)->process($this->paid(true), $this->handler());
        $this->assertSame([402, 0], [$refused->getStatusCode(), $this->runs]);

        $response = $this->middleware(fn () => self::tx(), upfront: true)->process($this->paid(true), $this->handler(500));
        $this->assertSame([500, 1], [$response->getStatusCode(), $this->runs]);
        $this->assertSame('failed_after_settle', end($this->outcomes)->status);
        $this->assertTrue(PaymentHeaders::decode($response->getHeaderLine(PaymentHeaders::RESPONSE))['success']);
    }

    public function testTheResourceIsTheSellers(): void
    {
        $resource = ['url' => 'https://shop.test/product/1', 'description' => 'One wisdom', 'mimeType' => 'text/markdown'];
        $response = $this->middleware(resource: fn () => $resource)->process(new ServerRequest('GET', 'https://shop.test/x?_format=md'), $this->handler());
        $this->assertSame($resource, PaymentHeaders::decode($response->getHeaderLine(PaymentHeaders::REQUIRED))['resource']);
    }
}
