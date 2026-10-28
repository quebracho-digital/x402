<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Charges for MCP tool calls with x402, in band (x402 transports-v2/mcp.md).
 *
 * For an MCP server over Streamable HTTP. A paid tools/call without payment
 * gets a tool result with isError and the PaymentRequired; the client
 * retries with the payment in params._meta["x402/payment"] and the receipt
 * comes back in result._meta["x402/payment-response"]. HTTP stays 200.
 */
final class X402McpMiddleware implements MiddlewareInterface {

  public const string PAYMENT = 'x402/payment';
  public const string PAYMENT_RESPONSE = 'x402/payment-response';

  /**
   * @param Scheme[] $schemes
   *   The schemes this seller verifies, one per chain family.
   * @param \Closure $price
   *   fn(array $params): ?array — the `accepts` list for a tools/call with
   *   these params (name, arguments), or NULL when the tool is free.
   * @param \Closure $claim
   *   fn(string $key): bool — TRUE the first time a payment is seen, FALSE
   *   after; atomic and durable (FileClaims, or a DB unique key). Runs before
   *   the handler, so a payment buys one delivery even before it settles.
   * @param ?\Closure $settle
   *   fn(array $payment, array $req): array — a facilitator's settle result.
   *   NULL is mock mode: signatures are checked for real, nothing moves.
   */
  public function __construct(
    private readonly array $schemes,
    private readonly \Closure $price,
    private readonly \Closure $claim,
    private readonly ResponseFactoryInterface $responses,
    private readonly StreamFactoryInterface $streams,
    private readonly ?\Closure $settle = NULL,
  ) {}

  public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
    $body = $request->getBody();
    $message = json_decode((string) $body, TRUE);
    // The transport reads the body after us.
    if ($body->isSeekable()) {
      $body->rewind();
    }
    if (!is_array($message)) {
      return $handler->handle($request);
    }
    if (array_is_list($message)) {
      // MCP 2025-06-18 dropped batches; a paid call in one would be charged
      // for the lot, whatever each call returned.
      foreach ($message as $m) {
        if (is_array($m) && $this->accepts($m) !== NULL) {
          return $this->json(['jsonrpc' => '2.0', 'id' => NULL, 'error' => ['code' => -32600, 'message' => 'Paid tools cannot be called in a batch']]);
        }
      }
      return $handler->handle($request);
    }
    $accepts = $this->accepts($message);
    if ($accepts === NULL) {
      return $handler->handle($request);
    }
    if (!array_key_exists('id', $message)) {
      // A notification gets no answer, and a paid tool does not run unpaid.
      return $this->responses->createResponse(202);
    }
    $id = $message['id'];
    $resource = ['url' => 'mcp://tool/' . $message['params']['name']];

    $payment = $message['params']['_meta'][self::PAYMENT] ?? NULL;
    if (!is_array($payment)) {
      return $this->required($id, $accepts, $resource, 'Payment required to access this resource');
    }
    // The offer the buyer says it took, among ours: never its own copy.
    $accepted = $payment['accepted'] ?? [];
    $req = current(array_filter($accepts, fn(array $a) => $a['scheme'] === ($accepted['scheme'] ?? NULL) && $a['network'] === ($accepted['network'] ?? NULL)));
    if ($req === FALSE) {
      return $this->required($id, $accepts, $resource, 'invalid_payment_requirements');
    }
    $scheme = current(array_filter($this->schemes, fn(Scheme $s) => $s->supports($req['network'])))
      ?: throw new \LogicException("No scheme verifies {$req['network']}.");
    // ponytail: verify runs before the MCP session check and costs an
    // ecrecover; rate-limit the route where it is deployed.
    $reason = $scheme->verify($payment, $req);
    if ($reason !== NULL) {
      return $this->required($id, $accepts, $resource, $reason);
    }
    // Spent before it runs: N concurrent copies get one delivery.
    if (!($this->claim)(Settlement::key($payment))) {
      return $this->required($id, $accepts, $resource, 'duplicate_settlement');
    }

    $response = $handler->handle($request);
    if ($response->getStatusCode() !== 200) {
      // The transport refused it (session, protocol): nothing ran.
      return $response;
    }
    $delivered = NULL;
    $settled = $this->rewrite($response, $id, function (array $out) use ($payment, $req, &$delivered): array {
      // A JSON-RPC error or a failed tool delivered nothing: nothing charged.
      $delivered = isset($out['result']) && empty($out['result']['isError']);
      if (!$delivered) {
        return $out;
      }
      $receipt = Settlement::run($this->settle, $payment, $req);
      if ($receipt['success'] !== TRUE) {
        return ['receipt' => $receipt];
      }
      $out['result']['_meta'][self::PAYMENT_RESPONSE] = $receipt;
      return $out;
    });
    if ($delivered === NULL) {
      // ponytail: our answer is not in the body (a live stream, e.g. a tool
      // that samples); withheld rather than given away unpaid.
      return $this->required($id, $accepts, $resource, 'unexpected_settle_error');
    }
    if (isset($settled['receipt'])) {
      // Settled nothing: the spec says return only the payment error.
      return $this->required($id, $accepts, $resource, $settled['receipt']['errorReason']);
    }
    return $settled['response'];
  }

  private function accepts(array $message): ?array {
    if (($message['method'] ?? NULL) !== 'tools/call' || !is_string($message['params']['name'] ?? NULL)) {
      return NULL;
    }
    return ($this->price)($message['params']);
  }

  /**
   * Applies $fn to our JSON-RPC answer, in a JSON or a buffered SSE body.
   *
   * @return array{response?: ResponseInterface, receipt?: array}
   */
  private function rewrite(ResponseInterface $response, mixed $id, \Closure $fn): array {
    $body = $response->getBody();
    if (!$body->isSeekable()) {
      return [];
    }
    $text = (string) $body;
    $sse = str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream');
    $chunks = $sse ? preg_split('/^data: /m', $text) : ['', $text];
    foreach ($chunks as $i => $chunk) {
      if ($i === 0) {
        continue;
      }
      [$data, $rest] = $sse ? explode("\n", $chunk, 2) + [1 => ''] : [$chunk, ''];
      $out = json_decode($data, TRUE);
      if (!is_array($out) || !array_key_exists('id', $out) || $out['id'] !== $id) {
        continue;
      }
      $out = $fn($out);
      if (isset($out['receipt'])) {
        return $out;
      }
      $chunks[$i] = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . ($sse ? "\n" . $rest : '');
      $text = $sse ? implode('data: ', $chunks) : $chunks[1];
      return ['response' => $response->withBody($this->streams->createStream($text))];
    }
    return [];
  }

  private function required(mixed $id, array $accepts, array $resource, string $error): ResponseInterface {
    $required = ['x402Version' => 2, 'error' => $error, 'resource' => $resource, 'accepts' => array_values($accepts)];
    return $this->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
      'isError' => TRUE,
      'structuredContent' => $required,
      'content' => [['type' => 'text', 'text' => json_encode($required, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]],
    ]]);
  }

  private function json(array $body): ResponseInterface {
    return $this->responses->createResponse(200)
      ->withHeader('Content-Type', 'application/json')
      ->withBody($this->streams->createStream(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
  }

}
