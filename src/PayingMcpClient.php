<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * A PSR-18 client that pays MCP tool calls in band (x402 transports-v2/mcp.md).
 *
 * Put it under any MCP Streamable HTTP client transport. A tools/call that
 * comes back as an isError result carrying a PaymentRequired is signed,
 * resent once with params._meta["x402/payment"], and the receipt is read
 * from result._meta["x402/payment-response"]. The budget is spent when it signs, not only when
 * that receipt says success === true.
 */
final class PayingMcpClient implements ClientInterface
{
    /**
     * @param Scheme[]                  $schemes
     *                                           The schemes this buyer can pay with, each holding its key
     * @param ?\Closure                 $onPaid
     *                                           fn(string $tool, array $req, array $receipt): void — every receipt of
     *                                           a paid retry, settled or not, e.g. to show or log it.
     * @param ?EventDispatcherInterface $events
     *                                           Dispatches an AddressCheck on each offer's payTo before signing it;
     *                                           NULL screens nothing
     * @param ?\Closure                 $onRefused
     *                                           fn(array $req, string $reason): void — each offer not paid, and why
     *                                           (no_scheme, not_allowed, or the screening's reason), e.g. to tell the
     *                                           user the price it refused
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly array $schemes,
        private readonly Budget $budget,
        private readonly StreamFactoryInterface $streams,
        private readonly ?\Closure $onPaid = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?\Closure $onRefused = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $body = $request->getBody();
        $call = json_decode((string) $body, true);
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $response = $this->client->sendRequest($request);
        if (!is_array($call) || ($call['method'] ?? null) !== 'tools/call' || !isset($call['id'])) {
            return $response;
        }
        [$response, $result] = $this->answer($response, $call['id']);
        $required = $result['structuredContent'] ?? null;
        if (empty($result['isError']) || !is_array($required) || ($required['x402Version'] ?? null) !== 2) {
            return $response;
        }

        // Expired signatures the seller never used no longer count.
        $this->budget->reclaim();
        // The first offer we have a scheme for and the budget allows.
        foreach ($required['accepts'] ?? [] as $req) {
            [$scheme, $reason] = $this->budget->choose($this->schemes, $req, $this->events);
            if (null !== $reason) {
                $this->onRefused?->__invoke(is_array($req) ? $req : [], $reason);
                continue;
            }
            // Spent when signed: a signed authorization is money the seller can
            // settle on its own, whatever it answers. Never refunded here.
            $this->budget->spend($req);
            $payment = $scheme->pay($required, $req);
            $call['params']['_meta'][X402McpMiddleware::PAYMENT] = $payment;
            $paid = $request->withBody($this->streams->createStream(json_encode($call, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
            [$response, $result] = $this->answer($this->client->sendRequest($paid), $call['id']);
            $receipt = $result['_meta'][X402McpMiddleware::PAYMENT_RESPONSE] ?? null;
            if (is_array($receipt)) {
                $this->onPaid?->__invoke((string) $call['params']['name'], $req, $receipt);
            }
            if (($receipt['success'] ?? null) !== true) {
                $this->budget->unsettled($req, $payment);
            }

            return $response;
        }

        // Nothing we can or may pay: the caller sees the PaymentRequired itself.
        return $response;
    }

    /**
     * Our JSON-RPC result, from a JSON or a buffered SSE body.
     *
     * @return array{ResponseInterface, ?array}
     *                                          The response with a fresh body (we read it), and the result
     */
    private function answer(ResponseInterface $response, mixed $id): array
    {
        $text = (string) $response->getBody();
        $response = $response->withBody($this->streams->createStream($text));
        // ponytail: SSE read as a whole body; a stream that stays open (a tool
        // that samples or reports progress forever) blocks here until it ends.
        $lines = str_contains(strtolower($response->getHeaderLine('Content-Type')), 'text/event-stream')
          ? (preg_match_all('/^data: ?(.*)$/m', $text, $m) ? $m[1] : [])
          : [$text];
        foreach ($lines as $line) {
            $out = json_decode($line, true);
            if (is_array($out) && ($out['id'] ?? null) === $id && is_array($out['result'] ?? null)) {
                return [$response, $out['result']];
            }
        }

        return [$response, null];
    }
}
