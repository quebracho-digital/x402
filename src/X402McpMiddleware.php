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
     *                                             The schemes this seller verifies, one per chain family
     * @param \Closure                  $price
     *                                             fn(array $params): ?array — the `accepts` list for a tools/call with
     *                                             these params (name, arguments), or NULL when the tool is free
     * @param \Closure                  $claim
     *                                             fn(string $key): bool — TRUE the first time a payment is seen, FALSE
     *                                             after; atomic and durable (FileClaims, or a DB unique key). Runs before
     *                                             the handler, so a payment buys one delivery even before it settles.
     * @param \Closure|Facilitator      $settle
     *                                             A Facilitator (HttpFacilitator, CircleFacilitator, ChainFacilitator, MockFacilitator): it settles,
     *                                             and it verifies online too unless $verify is given. Or a closure, to wrap the settle:
     *                                             fn(array $payment, array $req): array — a facilitator's settle result.
     *                                             Settlement::mock() is mock mode, never implicit: nothing moves.
     * @param ?EventDispatcherInterface $events
     *                                             Dispatches an AddressCheck on the payer, PaymentIntent, PaymentOutcome and PaymentFault; NULL: none
     * @param ?\Closure                 $verify
     *                                             fn(array $payment, array $req): ?string — online check before the claim.
     *                                             NULL when good, or a non-empty reason; any other return or exception
     *                                             refuses as `unexpected_verify_error`, except a FacilitatorException
     *                                             (`facilitator_unavailable`). Nothing is claimed on a refusal, as in X402Middleware
     * @param ?\Closure                 $release
     *                                             fn(string $key): void — frees a claim when nothing was charged
     * @param ?\Closure                 $resource
     *                                             fn(array $params): array — the PaymentRequired's resource (url,
     *                                             description, mimeType); default: mcp://tool/<name>
     * @param ?\Closure                 $validate
     *                                             fn(array $params): ?string — why these params cannot be sold, or NULL.
     *                                             Runs before any payment is read: a reason is a JSON-RPC -32602 error,
     *                                             nothing is claimed, settled or run (price() returning NULL means free,
     *                                             so it cannot refuse)
     * @param ?\Closure                 $payer
     *                                             fn(array $params, string $payer): ?string — once the signature proved
     *                                             who pays, why this payer cannot buy this call, or NULL; before the claim
     * @param ?\Closure                 $duration
     *                                             fn(array $params): int — worst-case seconds from the claim until the
     *                                             settle is sent; a signature that expires sooner (plus EvmExact::SLACK) is
     *                                             refused before the claim. 0 or NULL: no check (EVM validBefore only)
     * @param ?PaymentLedger            $ledger
     *                                             Keeps each payment's outcome: a retry gets the stored answer (replayed with
     *                                             its receipt, the handler not run again), or an isError result with reason
     *                                             in_progress, settlement_pending, settlement_unknown, needs_review or
     *                                             already_settled (HTTP stays 200, as for every MCP refusal). Replaces $claim
     *                                             and $release.
     * @param ?\Closure                 $lookup
     *                                             fn(array $state, array $payment, array $req): ?array — asks the chain
     *                                             about a 'pending' payment, as in X402Middleware
     * @param ?\Closure                 $sensitive
     *                                             fn(array $params): bool — TRUE when this call's answer must not be kept,
     *                                             as in X402Middleware: the state is stored, never the tool's answer.
     *                                             Called once per paid call; throwing or any answer but FALSE counts as
     *                                             sensitive. A retry of a settled sensitive call gets already_settled.
     * @param ?OfacSdn                  $ofac
     *                                             OFAC's SDN list, asked first about every address (AddressCheck), with or without $events;
     *                                             NULL: the one shipped with the library. Pass new OfacSdn($file) with the file OfacSdn::sync() keeps fresh
     * @param ?Stop                     $stop
     *                                             While its file exists the seller answers payments_paused (JSON-RPC -32000) before verifying or charging anything. NULL: no stop switch
     */
    private readonly \Closure $settle;
    private readonly ?\Closure $verify;

    public function __construct(
        private readonly array $schemes,
        private readonly \Closure $price,
        private readonly \Closure $claim,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        \Closure|Facilitator $settle,
        private readonly ?EventDispatcherInterface $events = null,
        ?\Closure $verify = null,
        private readonly ?\Closure $release = null,
        private readonly ?\Closure $resource = null,
        private readonly ?\Closure $validate = null,
        private readonly ?\Closure $payer = null,
        private readonly ?\Closure $duration = null,
        private readonly ?PaymentLedger $ledger = null,
        private readonly ?\Closure $lookup = null,
        private readonly ?\Closure $sensitive = null,
        private readonly ?OfacSdn $ofac = null,
        private readonly ?Stop $stop = null,
    ) {
        $this->settle = Settlement::settler($settle);
        // A facilitator given whole also verifies online, unless the caller passed its own check.
        $this->verify = $verify ?? ($settle instanceof Facilitator ? Settlement::verifier($settle) : null);
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
        $params = $message['params'];
        // Before the buyer signs or the payment is looked at: bad params cost nothing.
        $invalid = null !== $this->validate ? ($this->validate)($params) : null;
        if (null !== $invalid) {
            return $this->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32602, 'message' => $invalid, 'data' => ['reason' => 'invalid_params']]]);
        }
        $resource = null !== $this->resource ? ($this->resource)($message['params']) : ['url' => 'mcp://tool/'.$message['params']['name']];

        $payment = $message['params']['_meta'][self::PAYMENT] ?? null;
        if (!is_array($payment)) {
            // Paused: say so now, so the buyer does not sign a payment this seller would not take.
            if ($this->stop?->engaged()) {
                return $this->paused($id);
            }

            return $this->required($id, $accepts, $resource, 'Payment required to access this resource');
        }
        // The offer the buyer says it took, among ours: never its own copy.
        $accepted = $payment['accepted'] ?? [];
        $req = current(array_filter($accepts, fn (array $a) => $a['scheme'] === ($accepted['scheme'] ?? null) && $a['network'] === ($accepted['network'] ?? null)));
        if (false === $req) {
            return $this->required($id, $accepts, $resource, 'invalid_payment_requirements');
        }
        $payer = null !== $this->payer ? fn (string $from): ?string => ($this->payer)($params, $from) : null;
        $sale = $this->sale($payment, $req, $payer, null !== $this->duration ? ($this->duration)($params) : 0);
        $keep = !X402Middleware::sensitive($this->sensitive, $params);
        $run = null;
        $reason = null;
        try {
            if (null !== $this->ledger) {
                $stored = $sale->stored($this->ledger, $this->lookup);
                if (null !== $stored) {
                    if (isset($stored['refuse'])) {
                        return $this->required($id, $accepts, $resource, $stored['refuse']);
                    }
                    if (!isset($stored['run'])) {
                        return $this->replay($id, $accepts, $resource, $stored['state']);
                    }
                    // Paid upfront, tool never ran: run it now, no second settle (the 'handling' record comes before the tool).
                    $run = $stored['run'];
                }
            }
            // ponytail: verify runs before the MCP session check and costs an
            // ecrecover; rate-limit the route where it is deployed.
            // Stopped by hand: nothing new is verified, claimed, run or charged. A payment the ledger already holds
            // (replayed above, or an upfront one whose tool still has to run) is delivered: it was paid.
            if (null === $run && $this->stop?->engaged()) {
                return $this->paused($id);
            }
            $reason = null === $run ? $sale->admit() : null;
        } catch (LedgerUnavailableException) {
            // Before the tool: nothing charged, nothing run.
            return $this->required($id, $accepts, $resource, 'ledger_unavailable');
        }
        if (null !== $reason) {
            return $this->required($id, $accepts, $resource, $reason);
        }
        try {
            return $this->charge($request, $handler, $sale, $run, $id, $accepts, $resource, $keep);
        } finally {
            // After the outcome is written, whatever it was: the next retry finds it.
            $sale->endRun();
        }
    }

    /**
     * Settles and runs an admitted call, or runs an upfront one already charged ($run, its run claim held).
     *
     * @param ?array<string, mixed> $run
     */
    private function charge(ServerRequestInterface $request, RequestHandlerInterface $handler, Sale $sale, ?array $run, mixed $id, array $accepts, array $resource, bool $keep): ResponseInterface
    {
        $receipt = $run['receipt'] ?? null;
        if (null === $run && $sale->upfront()) {
            // The intention is stored before the money can move: if the store refuses it, nothing is sent.
            if (!$sale->intend($this->ledger, ['status' => 'settling', 'id' => $id])) {
                $sale->abandon(null);

                return $this->required($id, $accepts, $resource, 'ledger_unavailable');
            }
            // Switched on since the check in process(): nothing was sent yet, so nothing is.
            if ($this->stop?->engaged()) {
                $sale->abandon(null);

                return $this->paused($id);
            }
            // Money first; once it moved, the receipt goes with whatever the tool answers.
            $receipt = $sale->settle();
            $this->record($sale, $receipt, null, $id, false);
            if (true !== $receipt['success']) {
                return $this->required($id, $accepts, $resource, $receipt['errorReason']);
            }
        }
        if (null !== $receipt && !$sale->intend($this->ledger, ['status' => 'handling', 'receipt' => $receipt, 'handler_ran' => true, 'id' => $id])) {
            // Charged, the tool did not run: a retry runs it (the stored state still says so).
            $sale->ran(503);

            return $this->required($id, $accepts, $resource, 'ledger_unavailable');
        }

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            if (null === $receipt) {
                $sale->abandon(null);
            } else {
                // Paid and the tool failed: stays 'settled' with handler_ran false, so a retry runs it again.
                $this->record($sale, $receipt, null, $id, false);
                $sale->ran(500);
            }
            throw $e;
        }
        if (200 !== $response->getStatusCode()) {
            // The transport refused it (session, protocol): nothing ran.
            if (null === $receipt) {
                $sale->abandon($response->getStatusCode());
            } else {
                $this->record($sale, $receipt, null, $id, false);
                $sale->ran($response->getStatusCode());
            }

            return $response;
        }
        $delivered = null;
        $settled = $this->rewrite($response, $id, function (array $out) use ($sale, $id, &$receipt, &$delivered): array {
            // A JSON-RPC error or a failed tool delivered nothing: nothing charged.
            $delivered = isset($out['result']) && empty($out['result']['isError']);
            if (null === $receipt) {
                if (!$delivered) {
                    $sale->abandon(200);

                    return $out;
                }
                if ($this->stop?->engaged()) {
                    // Switched on while the tool ran: nothing charged, its answer is withheld.
                    $sale->abandon(200);

                    return ['receipt' => ['success' => false, 'errorReason' => Stop::REASON]];
                }
                if (!$sale->intend($this->ledger, ['status' => 'settling', 'id' => $id])) {
                    // Refused before the settle: nothing charged, the tool's answer is withheld.
                    $sale->abandon(200);

                    return ['receipt' => ['success' => false, 'errorReason' => 'ledger_unavailable']];
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

            return $this->receipted($out, $receipt);
        });
        if (null === $delivered) {
            if (null !== $receipt) {
                // Paid upfront: the tool ran (handler_ran stays true), its answer goes as it is.
                $this->record($sale, $receipt, null, $id);

                return $response;
            }
            // ponytail: our answer is not in the body (a live stream, e.g. a tool
            // that samples); withheld rather than given away unpaid.
            $sale->abandon(200);

            return $this->required($id, $accepts, $resource, 'unexpected_settle_error');
        }
        // $receipt is the failed one when the settle refused, else the one this answer carries. Upfront, a tool that
        // did not deliver (isError, a JSON-RPC error) is X402Middleware's non-2xx: 'settled' with handler_ran false,
        // nothing kept, so a retry runs it again with no second settle.
        $this->record($sale, $receipt, $keep && $delivered ? $response : null, $id, $delivered);
        if (isset($settled['receipt'])) {
            // Settled nothing: the spec says return only the payment error.
            return $this->required($id, $accepts, $resource, $settled['receipt']['errorReason']);
        }

        return $settled['response'];
    }

    /**
     * Puts the receipt in a JSON-RPC answer: in result._meta, or in error.data (the money moved).
     */
    private function receipted(array $out, array $receipt): array
    {
        if (isset($out['result'])) {
            $out['result']['_meta'][self::PAYMENT_RESPONSE] = $receipt;
        } elseif (isset($out['error'])) {
            // Upfront and the tool answered with a JSON-RPC error: the money moved,
            // so the buyer still gets the receipt (its transaction) in error.data.
            // An object is extended; anything else (a list, a string) is kept under "data".
            $data = $out['error']['data'] ?? null;
            $data = match (true) {
                null === $data, [] === $data => [],
                is_array($data) && !array_is_list($data) => $data,
                default => ['data' => $data],
            };
            // Ours always wins: a tool must not plant its own receipt.
            $data[self::PAYMENT_RESPONSE] = $receipt;
            $out['error']['data'] = $data;
        }

        return $out;
    }

    /**
     * The stored answer, with this call's id and the stored receipt.
     */
    private function replay(mixed $id, array $accepts, array $resource, array $state): ResponseInterface
    {
        $receipt = $state['receipt'];
        $settled = $this->rewrite(Settlement::restore($state['response'], $this->responses, $this->streams), $state['id'] ?? null, fn (array $out): array => $this->receipted(array_replace($out, ['id' => $id]), $receipt));

        return $settled['response'] ?? $this->required($id, $accepts, $resource, 'already_settled');
    }

    private function sale(array $payment, array $req, ?\Closure $payer, int $duration): Sale
    {
        if (null === $this->ledger) {
            return new Sale($this->schemes, $this->claim, $this->settle, $this->events, $this->verify, $this->release, $payment, $req, $payer, $duration, ofac: $this->ofac);
        }
        $ledger = $this->ledger;
        $ttl = Settlement::ttl($payment, [], $this->schemes);

        return new Sale($this->schemes, fn (string $key): bool => $ledger->claim($key, $ttl), $this->settle, $this->events, $this->verify, fn (string $key) => $ledger->release($key), $payment, $req, $payer, $duration, ofac: $this->ofac);
    }

    /**
     * Stores what happened, when there is a ledger: a settled payment (with the answer, if any), or a
     * pending one whose claim was kept. $id is the call's JSON-RPC id, for a replay.
     */
    private function record(Sale $sale, ?array $receipt, ?ResponseInterface $response, mixed $id, bool $handlerRan = true): void
    {
        // A null receipt (nothing settled) is never stored: the state written before the settle stands.
        if (null === $this->ledger || null === $receipt) {
            return;
        }
        $mark = $handlerRan ? [] : ['handler_ran' => false];
        // A failed store after a settle keeps the answer, as in X402Middleware::record (the claim holds until its TTL).
        // Any exception, not only LedgerUnavailableException: the money already moved (see X402Middleware::record).
        try {
            if (true === $receipt['success']) {
                $sale->remember($this->ledger, ['status' => 'settled', 'receipt' => $receipt, 'id' => $id] + $mark, $response);
            } elseif ($sale->held()) {
                $sale->remember($this->ledger, ['status' => 'pending', 'receipt' => $receipt, 'id' => $id] + $mark, $response);
            }
        } catch (\Throwable $e) {
            // The settle's PaymentOutcome already went out; the lost state is a PaymentFault (record): a person looks.
            $sale->fault(PaymentFault::RECORD, $e);
        }
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
            // The answer to this call: its id, and a result or an error. A message with a method is a request
            // of the server's own (sampling, elicitation), whose ids are another space and may equal this one.
            if (!is_array($out) || !array_key_exists('id', $out) || $out['id'] !== $id || array_key_exists('method', $out)
                || !(array_key_exists('result', $out) || array_key_exists('error', $out))) {
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

    private function paused(mixed $id): ResponseInterface
    {
        return $this->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32000, 'message' => 'Payments are paused', 'data' => ['reason' => Stop::REASON]]]);
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
