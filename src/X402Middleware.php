<?php

namespace Quebracho\X402;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Charges for a request with x402: 402 → verify → run → settle.
 *
 * Which requests cost what is the caller's call ($price), so one endpoint
 * can be free for some calls and paid for others (an MCP tools/call).
 */
final class X402Middleware implements MiddlewareInterface {

  /**
   * @param Scheme[] $schemes
   *   The schemes this seller verifies, one per chain family.
   * @param \Closure $price
   *   fn(ServerRequestInterface): ?array — the `accepts` list (payment
   *   requirements) for a request, or NULL when it is free.
   * @param ?\Closure $settle
   *   fn(array $payment, array $req): array — a facilitator's settle result
   *   (success, transaction, network, payer, errorReason). NULL is mock
   *   mode: signatures are checked for real, nothing moves on chain.
   */
  public function __construct(
    private readonly array $schemes,
    private readonly \Closure $price,
    private readonly ResponseFactoryInterface $responses,
    private readonly StreamFactoryInterface $streams,
    private readonly ?\Closure $settle = NULL,
  ) {}

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    $accepts = ($this->price)($request);
    if ($accepts === NULL) {
      return $handler->handle($request);
    }
    $resource = ['url' => (string) $request->getUri()];

    $header = $request->getHeaderLine(PaymentHeaders::SIGNATURE);
    if ($header === '') {
      return $this->required($accepts, $resource, 'PAYMENT-SIGNATURE header is required');
    }
    try {
      $payment = PaymentHeaders::decode($header);
    }
    catch (InvalidPaymentException $e) {
      return $this->json(400, ['error' => $e->getMessage()]);
    }

    // The offer the buyer says it took, among ours: never its own copy.
    $accepted = $payment['accepted'] ?? [];
    $req = current(array_filter($accepts, fn(array $a) => $a['scheme'] === ($accepted['scheme'] ?? NULL) && $a['network'] === ($accepted['network'] ?? NULL)));
    if ($req === FALSE) {
      return $this->required($accepts, $resource, 'The payment matches none of the accepted offers');
    }
    $scheme = current(array_filter($this->schemes, fn(Scheme $s) => $s->supports($req['network'])))
      ?: throw new \LogicException("No scheme verifies {$req['network']}.");
    // ponytail: no spent-nonce store, so a payment replays until it expires
    // in mock mode; a real facilitator rejects the spent nonce on settle.
    $reason = $scheme->verify($payment, $req);
    if ($reason !== NULL) {
      return $this->required($accepts, $resource, $reason);
    }

    $response = $handler->handle($request);
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      // Nothing delivered, nothing charged.
      return $response;
    }
    $receipt = $this->settle
      ? ($this->settle)($payment, $req)
      : ['success' => TRUE, 'transaction' => '', 'network' => $req['network'], 'payer' => $payment['payload']['authorization']['from'] ?? $payment['payload']['sender_id'] ?? NULL];
    if (empty($receipt['success'])) {
      return $this->required($accepts, $resource, $receipt['errorReason'] ?? 'settlement_failed');
    }
    return $response->withHeader(PaymentHeaders::RESPONSE, PaymentHeaders::encode($receipt));
  }

  private function required(array $accepts, array $resource, string $error): ResponseInterface {
    $body = ['x402Version' => 2, 'error' => $error, 'resource' => $resource, 'accepts' => array_values($accepts)];
    return $this->json(402, $body)->withHeader(PaymentHeaders::REQUIRED, PaymentHeaders::encode($body));
  }

  private function json(int $status, array $body): ResponseInterface {
    return $this->responses->createResponse($status)
      ->withHeader('Content-Type', 'application/json')
      ->withBody($this->streams->createStream(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
  }

}
