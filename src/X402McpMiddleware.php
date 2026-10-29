<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;
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
final class X402McpMiddleware implements MiddlewareInterface
{
    public const string PAYMENT = 'x402/payment';
    public const string PAYMENT_RESPONSE = 'x402/payment-response';

    /**
     * @param Scheme[]                  $schemes
     *                                            The schemes this seller verifies, one per chain family
     * @param \Closure                  $price
     *                                            fn(array $params): ?array — the `accepts` list for a tools/call with
     *                                            these params (name, arguments), or NULL when the tool is free
     * @param \Closure                  $claim
     *                                            fn(string $key): bool — TRUE the first time a payment is seen, FALSE
     *                                            after; atomic and durable (FileClaims, or a DB unique key). Runs before
     *                                            the handler, so a payment buys one delivery even before it settles.
     * @param ?\Closure                 $settle
     *                                            fn(array $payment, array $req): array — a facilitator's settle result.
     *                                            NULL is mock mode: signatures are checked for real, nothing moves.
     * @param ?EventDispatcherInterface $events
     *                                            Dispatches an AddressCheck on the payer and a PaymentOutcome; NULL: neither
     * @param ?\Closure                 $verify
     *                                            fn(array $payment, array $req): ?string — online check before the claim,
     *                                            as in X402Middleware
     * @param ?\Closure                 $release
     *                                            fn(string $key): void — frees a claim when nothing was charged
     * @param ?\Closure                 $resource
     *                                            fn(array $params): array — the PaymentRequired's resource (url,
     *                                            description, mimeType); default: mcp://tool/<name>
     */
    public function __construct(
        private readonly array $schemes,
        private readonly \Closure $price,
        private readonly \Closure $claim,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly ?\Closure $settle = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?\Closure $verify = null,
        private readonly ?\Closure $release = null,
        private readonly ?\Closure $resource = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = $request->getBody();
        $message = json_decode((string) $body, true);
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
                if (is_array($m) && null !== $this->accepts($m)) {
                    return $this->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Paid tools cannot be called in a batch']]);
                }
            }

            return $handler->handle($request);
        }
        $accepts = $this->accepts($message);
        if (null === $accepts) {
            return $handler->handle($request);
        }
        if (!array_key_exists('id', $message)) {
            // A notification gets no answer, and a paid tool does not run unpaid.
            return $this->responses->createResponse(202);
        }
        $id = $message['id'];
        $resource = null !== $this->resource ? ($this->resource)($message['params']) : ['url' => 'mcp://tool/'.$message['params']['name']];

        $payment = $message['params']['_meta'][self::PAYMENT] ?? null;
        if (!is_array($payment)) {
            return $this->required($id, $accepts, $resource, 'Payment required to access this resource');
        }
        // The offer the buyer says it took, among ours: never its own copy.
        $accepted = $payment['accepted'] ?? [];
        $req = current(array_filter($accepts, fn (array $a) => $a['scheme'] === ($accepted['scheme'] ?? null) && $a['network'] === ($accepted['network'] ?? null)));
        if (false === $req) {
            return $this->required($id, $accepts, $resource, 'invalid_payment_requirements');
        }
        $sale = new Sale($this->schemes, $this->claim, $this->settle, $this->events, $this->verify, $this->release, $payment, $req);
        // ponytail: verify runs before the MCP session check and costs an
        // ecrecover; rate-limit the route where it is deployed.
        $reason = $sale->admit();
        if (null !== $reason) {
            return $this->required($id, $accepts, $resource, $reason);
        }

        $receipt = null;
        if ($sale->upfront()) {
            // Money first; once it moved, the receipt goes with whatever the tool answers.
            $receipt = $sale->settle();
            if (true !== $receipt['success']) {
                return $this->required($id, $accepts, $resource, $receipt['errorReason']);
            }
        }

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            null === $receipt ? $sale->abandon(null) : $sale->ran(500);
            throw $e;
        }
        if (200 !== $response->getStatusCode()) {
            // The transport refused it (session, protocol): nothing ran.
            null === $receipt ? $sale->abandon($response->getStatusCode()) : $sale->ran($response->getStatusCode());

            return $response;
        }
        $delivered = null;
        $settled = $this->rewrite($response, $id, function (array $out) use ($sale, $receipt, &$delivered): array {
            // A JSON-RPC error or a failed tool delivered nothing: nothing charged.
            $delivered = isset($out['result']) && empty($out['result']['isError']);
            if (null === $receipt) {
                if (!$delivered) {
                    $sale->abandon(200);

                    return $out;
                }
                $receipt = $sale->settle(200);
                if (true !== $receipt['success']) {
                    return ['receipt' => $receipt];
                }
            }
            if (!$delivered) {
                // Upfront, charged: the tool failed after the money moved.
                $sale->ran(500);
            }
            if (isset($out['result'])) {
                $out['result']['_meta'][self::PAYMENT_RESPONSE] = $receipt;
            }

            return $out;
        });
        if (null === $delivered) {
            if (null !== $receipt) {
                // Paid upfront: the answer goes as it is.
                return $response;
            }
            // ponytail: our answer is not in the body (a live stream, e.g. a tool
            // that samples); withheld rather than given away unpaid.
            $sale->abandon(200);

            return $this->required($id, $accepts, $resource, 'unexpected_settle_error');
        }
        if (isset($settled['receipt'])) {
            // Settled nothing: the spec says return only the payment error.
            return $this->required($id, $accepts, $resource, $settled['receipt']['errorReason']);
        }

        return $settled['response'];
    }

    private function accepts(array $message): ?array
    {
        if (($message['method'] ?? null) !== 'tools/call' || !is_string($message['params']['name'] ?? null)) {
            return null;
        }

        return ($this->price)($message['params']);
    }

    /**
     * Applies $fn to our JSON-RPC answer, in a JSON or a buffered SSE body.
     *
     * @return array{response?: ResponseInterface, receipt?: array}
     */
    private function rewrite(ResponseInterface $response, mixed $id, \Closure $fn): array
    {
        $body = $response->getBody();
        if (!$body->isSeekable()) {
            return [];
        }
        $text = (string) $body;
        $sse = str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream');
        $chunks = $sse ? preg_split('/^data: /m', $text) : ['', $text];
        foreach ($chunks as $i => $chunk) {
            if (0 === $i) {
                continue;
            }
            [$data, $rest] = $sse ? explode("\n", $chunk, 2) + [1 => ''] : [$chunk, ''];
            $out = json_decode($data, true);
            if (!is_array($out) || !array_key_exists('id', $out) || $out['id'] !== $id) {
                continue;
            }
            $out = $fn($out);
            if (isset($out['receipt'])) {
                return $out;
            }
            $chunks[$i] = json_encode($out, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).($sse ? "\n".$rest : '');
            $text = $sse ? implode('data: ', $chunks) : $chunks[1];

            return ['response' => $response->withBody($this->streams->createStream($text))];
        }

        return [];
    }

    private function required(mixed $id, array $accepts, array $resource, string $error): ResponseInterface
    {
        $required = ['x402Version' => 2, 'error' => $error, 'resource' => $resource, 'accepts' => array_values($accepts)];

        return $this->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => [
            'isError' => true,
            'structuredContent' => $required,
            'content' => [['type' => 'text', 'text' => json_encode($required, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)]],
        ]]);
    }

    private function json(array $body): ResponseInterface
    {
        return $this->responses->createResponse(200)
          ->withHeader('Content-Type', 'application/json')
          ->withBody($this->streams->createStream(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }
}
