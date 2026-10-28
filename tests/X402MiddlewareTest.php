<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Quebracho\X402\EvmExact;
use Quebracho\X402\FileClaims;
use Quebracho\X402\Networks;
use Quebracho\X402\PaymentHeaders;
use Quebracho\X402\Signer;
use Quebracho\X402\X402Middleware;

final class X402MiddlewareTest extends TestCase
{
    private int $runs = 0;

    private function middleware(?\Closure $settle = null): X402Middleware
    {
        $req = Networks::requirements('eip155:84532', '0.01', '0x000000000000000000000000000000000000dEaD');
        $f = new Psr17Factory();
        return new X402Middleware([new EvmExact()], fn(ServerRequestInterface $r) => $r->getUri()->getPath() === '/free' ? null : [$req], (new FileClaims(sys_get_temp_dir() . '/x402-claims-' . bin2hex(random_bytes(8))))->__invoke(...), $f, $f, $settle);
    }

    private function handler(int $status = 200): RequestHandlerInterface
    {
        return new class ($this->runs, $status) implements RequestHandlerInterface {
            public function __construct(private int &$runs, private int $status) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->runs++;
                return new Response($this->status, [], 'paid content');
            }
        };
    }

    private function paidRequest(): ServerRequest
    {
        $first = $this->middleware()->process(new ServerRequest('POST', 'https://example.test/mcp'), $this->handler());
        $required = PaymentHeaders::decode($first->getHeaderLine(PaymentHeaders::REQUIRED));
        $buyer = new EvmExact(new Signer('0x4c0883a69102937d6231471b5dbb6204fe5129617082792ae468d01a3f362318'));
        $payment = $buyer->pay($required, $required['accepts'][0]);
        return new ServerRequest('POST', 'https://example.test/mcp', [PaymentHeaders::SIGNATURE => PaymentHeaders::encode($payment)]);
    }

    public function testFreeRequestsPassThrough(): void
    {
        self::assertSame(200, $this->middleware()->process(new ServerRequest('GET', 'https://example.test/free'), $this->handler())->getStatusCode());
    }

    public function testUnpaidRequestGets402WithoutRunning(): void
    {
        $response = $this->middleware()->process(new ServerRequest('POST', 'https://example.test/mcp'), $this->handler());
        self::assertSame(402, $response->getStatusCode());
        self::assertSame('10000', PaymentHeaders::decode($response->getHeaderLine(PaymentHeaders::REQUIRED))['accepts'][0]['amount']);
        self::assertSame(0, $this->runs);
    }

    public function testPaidRequestRunsAndGetsAReceipt(): void
    {
        $response = $this->middleware()->process($this->paidRequest(), $this->handler());
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('paid content', (string) $response->getBody());
        self::assertTrue(PaymentHeaders::decode($response->getHeaderLine(PaymentHeaders::RESPONSE))['success']);
    }

    public function testTamperedPaymentGets402WithoutRunning(): void
    {
        $request = $this->paidRequest();
        $payment = PaymentHeaders::decode($request->getHeaderLine(PaymentHeaders::SIGNATURE));
        $payment['payload']['authorization']['value'] = '1';
        $response = $this->middleware()->process($request->withHeader(PaymentHeaders::SIGNATURE, PaymentHeaders::encode($payment)), $this->handler());
        self::assertSame(402, $response->getStatusCode());
        self::assertSame('invalid_exact_evm_payload_authorization_value_mismatch', json_decode((string) $response->getBody(), true)['error']);
        self::assertSame(0, $this->runs);
    }

    public function testGarbageHeaderGets400(): void
    {
        $request = new ServerRequest('POST', 'https://example.test/mcp', [PaymentHeaders::SIGNATURE => 'not base64!']);
        self::assertSame(400, $this->middleware()->process($request, $this->handler())->getStatusCode());
    }

    public function testFailedHandlerIsNotSettled(): void
    {
        $settled = false;
        $response = $this->middleware(function () use (&$settled) { $settled = true; return ['success' => true]; })
            ->process($this->paidRequest(), $this->handler(500));
        self::assertSame(500, $response->getStatusCode());
        self::assertFalse($settled);
    }

    public function testFailedSettlementWithholdsTheResponse(): void
    {
        $response = $this->middleware(fn() => ['success' => false, 'errorReason' => 'insufficient_funds'])
            ->process($this->paidRequest(), $this->handler());
        self::assertSame(402, $response->getStatusCode());
        self::assertStringNotContainsString('paid content', (string) $response->getBody());
        $receipt = PaymentHeaders::decode($response->getHeaderLine(PaymentHeaders::RESPONSE));
        self::assertFalse($receipt['success']);
        self::assertSame('insufficient_funds', $receipt['errorReason']);
    }
}
