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
 *
 * The wrapped client must not follow redirects: one to another host would
 * hand it the signed payment, which it could settle without delivering.
 */
final class PayingMcpClient implements ClientInterface
{
    /**
     * @param Scheme[]                  $schemes
     *                                             The schemes this buyer can pay with, each holding its key
     * @param ?\Closure                 $onPaid
     *                                             fn(string $tool, array $req, array $receipt): void — every receipt of
     *                                             a paid retry, settled or not, e.g. to show or log it.
     * @param ?EventDispatcherInterface $events
     *                                             Dispatches an AddressCheck on each offer's payTo before signing it, and reports
     *                                             PurchaseIntent, PurchaseOutcome and BudgetReclaim; NULL: only OFAC screens
     * @param ?\Closure                 $onRefused
     *                                             fn(array $req, string $reason): void — each offer not paid, and why
     *                                             (no_scheme, not_allowed, or the screening's reason), e.g. to tell the
     *                                             user the price it refused
     * @param ?OfacSdn                  $ofac
     *                                             OFAC's SDN list, asked first about every address (AddressCheck), with or without $events;
     *                                             NULL: the one shipped with the library. Pass new OfacSdn($file) with the file OfacSdn::sync() keeps fresh
     * @param ?Stop                     $stop
     *                                             While its file exists nothing is signed: the offer is refused as payments_paused and the caller sees the PaymentRequired itself. NULL: no stop switch
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly array $schemes,
        private readonly Budget $budget,
        private readonly StreamFactoryInterface $streams,
        private readonly ?\Closure $onPaid = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?\Closure $onRefused = null,
        private readonly ?OfacSdn $ofac = null,
        private readonly ?Stop $stop = null,
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
        if ($this->stop?->engaged()) {
            // Stopped by hand: nothing is reserved or signed, the caller sees the PaymentRequired itself.
            Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::REFUSED, [], Stop::REASON, tool: (string) $call['params']['name']));
            try {
                $this->onRefused?->__invoke([], Stop::REASON);
            } catch (\Throwable) {
                // ponytail: as for any refusal, a failing callback is dropped.
            }

            return $response;
        }

        // Expired signatures the seller never used no longer count.
        $this->budget->reclaim($this->events);
        // The first offer we have a scheme for and the budget allows.
        foreach ($required['accepts'] ?? [] as $req) {
            [$scheme, $reason] = $this->budget->choose($this->schemes, $req, $this->events, $this->ofac);
            // Checked again and counted in one step: the screening may have yielded, and
            // payments running concurrently on this budget must not all pass the cap.
            if (null === $reason && !$this->budget->reserve($req, $this->schemes)) {
                $reason = 'not_allowed';
            }
            // Switched on while the screening ran: the reservation is given back and nothing is signed.
            if (null === $reason && $this->stop?->engaged()) {
                $this->budget->release($req);
                $reason = Stop::REASON;
            }
            if (null !== $reason) {
                Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::REFUSED, is_array($req) ? $req : [], $reason, tool: (string) $call['params']['name']));
                try {
                    $this->onRefused?->__invoke(is_array($req) ? $req : [], $reason);
                } catch (\Throwable) {
                    // ponytail: a failing refusal callback is dropped; the PaymentRequired still goes to the caller.
                }
                if (Stop::REASON === $reason) {
                    return $response;
                }
                continue;
            }
            // Reserved, then signed: a signed authorization is money the seller can settle
            // on its own, whatever it answers.
            try {
                $payment = $scheme->pay($required, $req);
                $call['params']['_meta'][X402McpMiddleware::PAYMENT] = $payment;
                $paid = $request->withBody($this->streams->createStream(json_encode($call, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
            } catch (\Throwable $e) {
                // Never left this process: a failed signature spends nothing.
                $this->budget->release($req);
                Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::RELEASED, $req, error: $e, tool: (string) $call['params']['name']));

                throw $e;
            }
            Events::report($this->events, PurchaseIntent::of($scheme, $payment, $req));
            try {
                [$response, $result] = $this->answer($this->client->sendRequest($paid), $call['id']);
            } catch (\Throwable $e) {
                // It may have reached the seller: kept as unsettled, to be reclaimed once expired.
                $this->budget->unsettled($req, $payment);
                Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::UNSETTLED, $req, error: $e, tool: (string) $call['params']['name']));

                throw $e;
            }
            $receipt = $result['_meta'][X402McpMiddleware::PAYMENT_RESPONSE] ?? null;
            if (is_array($receipt)) {
                try {
                    $this->onPaid?->__invoke((string) $call['params']['name'], $req, $receipt);
                } catch (\Throwable) {
                    // ponytail: errors of a logging callback are dropped, as Sale::outcome drops them.
                }
            }
            $tool = (string) $call['params']['name'];
            if (($receipt['success'] ?? null) !== true) {
                $this->budget->unsettled($req, $payment);
                $reason = is_array($receipt) && is_string($receipt['errorReason'] ?? null) ? $receipt['errorReason'] : null;
                Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::UNSETTLED, $req, $reason, is_array($receipt) ? $receipt : null, tool: $tool));
            } else {
                Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::PAID, $req, receipt: $receipt, tool: $tool));
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
