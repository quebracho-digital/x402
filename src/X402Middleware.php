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
 * Charges for a request with x402: 402 → verify → run → settle.
 *
 * Which requests cost what is the caller's call ($price), so one endpoint
 * can be free for some calls and paid for others (an MCP tools/call).
 */
final class X402Middleware implements MiddlewareInterface
{
    /**
     * @param Scheme[]                  $schemes
     *                                             The schemes this seller verifies, one per chain family
     * @param \Closure                  $price
     *                                             fn(ServerRequestInterface): ?array — the `accepts` list (payment
     *                                             requirements) for a request, or NULL when it is free
     * @param \Closure                  $claim
     *                                             fn(string $key): bool — TRUE the first time a payment is seen, FALSE
     *                                             after; atomic and durable (FileClaims, or a DB unique key). Runs before
     *                                             the handler, so a payment buys one delivery even before it settles.
     * @param \Closure                  $settle
     *                                             fn(array $payment, array $req): array — a facilitator's settle result
     *                                             (success, transaction, network, payer, errorReason). Settlement::mock()
     *                                             is mock mode, never implicit: nothing moves on chain.
     *                                             A FacilitatorException answers 502: not the buyer's fault.
     * @param ?EventDispatcherInterface $events
     *                                             Dispatches an AddressCheck on the payer, PaymentIntent, PaymentOutcome and PaymentFault; NULL: none
     * @param ?\Closure                 $verify
     *                                             fn(array $payment, array $req): ?string — an online check (facilitator
     *                                             /verify, eth_call) after the offline one, before the claim. Returns NULL
     *                                             when good, or a non-empty reason string. Any other return (an empty
     *                                             string, FALSE, an array) or any exception refuses as
     *                                             `unexpected_verify_error` (402), except a FacilitatorException, which
     *                                             answers 502. Nothing is claimed and the handler does not run on a refusal.
     *                                             NULL skips the check: the handler may run for a payment
     *                                             settle then refuses (its output withheld).
     * @param ?\Closure                 $release
     *                                             fn(string $key): void — frees a claim when nothing was charged (the
     *                                             handler failed, or settle failed with no transaction), so the buyer's
     *                                             signature is good again. NULL keeps every claim.
     * @param ?\Closure                 $resource
     *                                             fn(ServerRequestInterface): array — the 402's resource (url,
     *                                             description, mimeType); default: the request URL
     * @param ?PaymentLedger            $ledger
     *                                             Keeps each payment's outcome, so a retry gets the stored response
     *                                             (replayed, not run again), 409 while it is in progress (also while
     *                                             another retry runs an upfront handler that did not deliver), or 409 when
     *                                             it settled without a response to keep. Also 409 settlement_unknown
     *                                             (a settle sent, outcome lost, no reference) and needs_review (upfront
     *                                             handler started, never closed). Replaces $claim and $release.
     * @param ?\Closure                 $lookup
     *                                             fn(array $state, array $payment, array $req): ?array — asks the chain
     *                                             about a 'pending' payment: a receipt with success true settles it, success
     *                                             false frees it, NULL (or no lookup) answers 409 settlement_pending. A
     *                                             success whose network, transaction, paymentId or payer is not this
     *                                             payment's stays pending.
     *                                             A 'settling' state (outcome lost) is looked up only with a transaction
     *                                             or paymentId; otherwise, or when unknown, 409 settlement_unknown.
     * @param ?\Closure                 $sensitive
     *                                             fn(ServerRequestInterface): bool — TRUE when this request's answer must
     *                                             not be kept: the ledger stores the payment's state (claim, settling,
     *                                             handling, receipt) but never the response body. Called once per paid
     *                                             request, before anything is stored; one that throws, or returns anything
     *                                             but FALSE, counts as sensitive. A retry of a sensitive payment already
     *                                             settled gets 409 already_settled, not the body. NULL: every answer is kept.
     * @param ?OfacSdn                  $ofac
     *                                             OFAC's SDN list, asked first about every address (AddressCheck), with or without $events;
     *                                             NULL: the one shipped with the library. Pass new OfacSdn($file) with the file OfacSdn::sync() keeps fresh
     * @param ?Stop                     $stop
     *                                             While its file exists the seller answers payments_paused (503) before verifying or charging anything. NULL: no stop switch
     */
    public function __construct(
        private readonly array $schemes,
        private readonly \Closure $price,
        private readonly \Closure $claim,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly \Closure $settle,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?\Closure $verify = null,
        private readonly ?\Closure $release = null,
        private readonly ?\Closure $resource = null,
        private readonly ?PaymentLedger $ledger = null,
        private readonly ?\Closure $lookup = null,
        private readonly ?\Closure $sensitive = null,
        private readonly ?OfacSdn $ofac = null,
        private readonly ?Stop $stop = null,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $accepts = ($this->price)($request);
        if (null === $accepts) {
            return $handler->handle($request);
        }
        $resource = null !== $this->resource ? ($this->resource)($request) : ['url' => (string) $request->getUri()];

        $header = $request->getHeaderLine(PaymentHeaders::SIGNATURE);
        if ('' === $header) {
            // Paused: say so now, so the buyer does not sign a payment this seller would not take.
            if ($this->stop?->engaged()) {
                return $this->paused();
            }

            return $this->required($accepts, $resource, 'PAYMENT-SIGNATURE header is required');
        }
        try {
            $payment = PaymentHeaders::decode($header);
        } catch (InvalidPaymentException $e) {
            return $this->json(400, ['error' => $e->getMessage()]);
        }

        // The offer the buyer says it took, among ours: never its own copy.
        $accepted = $payment['accepted'] ?? [];
        $req = current(array_filter($accepts, fn (array $a) => $a['scheme'] === ($accepted['scheme'] ?? null) && $a['network'] === ($accepted['network'] ?? null)));
        if (false === $req) {
            return $this->required($accepts, $resource, 'The payment matches none of the accepted offers');
        }
        $sale = $this->sale($payment, $req);
        $keep = !self::sensitive($this->sensitive, $request);
        $run = null;
        try {
            if (null !== $this->ledger) {
                $stored = $sale->stored($this->ledger, $this->lookup);
                if (null !== $stored) {
                    if (isset($stored['refuse'])) {
                        return $this->json(409, ['error' => $stored['refuse']]);
                    }
                    if (!isset($stored['run'])) {
                        return $this->replay($stored['state']);
                    }
                    // Paid upfront, handler never ran: run it now, no second settle (deliverUpfront records it first).
                    $run = $stored['run'];
                }
            }
            // Stopped by hand: nothing new is verified, claimed, run or charged. A payment the ledger already holds
            // (replayed above, or an upfront one whose handler still has to run) is delivered: it was paid.
            if (null === $run && $this->stop?->engaged()) {
                return $this->paused();
            }
            $reason = null === $run ? $sale->admit() : null;
        } catch (LedgerUnavailableException) {
            // Before the handler: nothing charged, nothing run.
            return $this->json(503, ['error' => 'ledger_unavailable']);
        }
        if (Sale::UNAVAILABLE === $reason) {
            return $this->json(502, ['error' => $reason]);
        }
        if (null !== $reason) {
            return $this->required($accepts, $resource, $reason);
        }

        if (null !== $run) {
            try {
                return $this->deliverUpfront($request, $handler, $sale, $run['receipt'], $keep);
            } finally {
                // After the outcome is written, whatever it was: the next retry finds it.
                $sale->endRun();
            }
        }
        if ($sale->upfront()) {
            // The intention is stored before the money can move: if the store refuses it, nothing is sent.
            if (!$sale->intend($this->ledger, ['status' => 'settling'])) {
                $sale->abandon(null);

                return $this->json(503, ['error' => 'ledger_unavailable']);
            }
            // Switched on since the check above: nothing was sent yet, so nothing is.
            if ($this->stop?->engaged()) {
                $sale->abandon(null);

                return $this->paused();
            }
            // Money first; once it moved, the receipt goes with whatever the handler answers.
            $receipt = $sale->settle();
            $this->record($sale, $receipt, null, false);
            if (true !== $receipt['success']) {
                return $this->failed($accepts, $resource, $receipt);
            }

            return $this->deliverUpfront($request, $handler, $sale, $receipt, $keep);
        }

        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            $sale->abandon(null);
            throw $e;
        }
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            // Nothing delivered, nothing charged.
            $sale->abandon($status);

            return $response;
        }
        if ($this->stop?->engaged()) {
            // Switched on while the handler ran: nothing charged, its answer is withheld.
            $sale->abandon($status);

            return $this->paused();
        }
        if (!$sale->intend($this->ledger, ['status' => 'settling'])) {
            // Refused before the settle: nothing charged, the handler's answer is withheld.
            $sale->abandon($status);

            return $this->json(503, ['error' => 'ledger_unavailable']);
        }
        $receipt = $sale->settle($status);
        $this->record($sale, $receipt, $keep ? $response : null);
        if (true !== $receipt['success']) {
            return $this->failed($accepts, $resource, $receipt);
        }

        return $response->withHeader(PaymentHeaders::RESPONSE, PaymentHeaders::encode($receipt));
    }

    private function paused(): ResponseInterface
    {
        return $this->json(503, ['error' => Stop::REASON])->withHeader('Retry-After', '300');
    }

    /**
     * The claim and release come from the ledger when there is one, else from the closures.
     */
    private function sale(array $payment, array $req): Sale
    {
        if (null === $this->ledger) {
            return new Sale($this->schemes, $this->claim, $this->settle, $this->events, $this->verify, $this->release, $payment, $req, ofac: $this->ofac);
        }
        $ledger = $this->ledger;
        $ttl = Sale::ttl($payment);

        return new Sale($this->schemes, fn (string $key): bool => $ledger->claim($key, $ttl), $this->settle, $this->events, $this->verify, fn (string $key) => $ledger->release($key), $payment, $req, ofac: $this->ofac);
    }

    /**
     * Upfront, the money has moved: runs the handler and stores its answer with the receipt. A handler that
     * does not deliver (a non-2xx, or an exception) leaves the state 'settled' with handler_ran false, so a
     * retry runs the handler again, with no second settle.
     */
    private function deliverUpfront(ServerRequestInterface $request, RequestHandlerInterface $handler, Sale $sale, array $receipt, bool $keep): ResponseInterface
    {
        // Written before the handler: if the process dies inside it, a retry finds 'handling' and does not run it again.
        if (!$sale->intend($this->ledger, ['status' => 'handling', 'receipt' => $receipt, 'handler_ran' => true])) {
            // The handler did not run, so the stored state still lets a retry run it. Charged: failed_after_settle.
            $sale->ran(503);

            return $this->json(503, ['error' => 'ledger_unavailable'])->withHeader(PaymentHeaders::RESPONSE, PaymentHeaders::encode($receipt));
        }
        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            // Paid and failed: recorded, so the seller can refund by hand; the buyer's retry runs it again.
            $this->record($sale, $receipt, null, false);
            $sale->ran(500);
            throw $e;
        }
        $status = $response->getStatusCode();
        $sale->ran($status);
        $delivered = $status >= 200 && $status < 300;
        $this->record($sale, $receipt, $delivered && $keep ? $response : null, $delivered);

        return $response->withHeader(PaymentHeaders::RESPONSE, PaymentHeaders::encode($receipt));
    }

    /**
     * Stores what happened, when there is a ledger: a settled payment (with the response, if any), or a
     * pending one whose claim was kept. $handlerRan false marks an upfront payment whose handler has not
     * delivered yet.
     *
     * A store that fails here comes after the money may have moved (or was refused): the answer is given anyway,
     * never a 503 that would tell the buyer nothing was charged. The claim stays until its TTL, so no second
     * payment is taken; the buyer's retry gets 409 in_progress, already_settled or settlement_unknown instead of a replay.
     * Any exception counts, not only LedgerUnavailableException: a store that fails in another way (a DB
     * error, a bad state) after a settle must not turn a paid sale into a 500 the buyer cannot act on.
     */
    private function record(Sale $sale, array $receipt, ?ResponseInterface $response = null, bool $handlerRan = true): void
    {
        if (null === $this->ledger) {
            return;
        }
        $mark = $handlerRan ? [] : ['handler_ran' => false];
        try {
            if (true === $receipt['success']) {
                $sale->remember($this->ledger, ['status' => 'settled', 'receipt' => $receipt] + $mark, $response);
            } elseif ($sale->held()) {
                $sale->remember($this->ledger, ['status' => 'pending', 'receipt' => $receipt] + $mark, $response);
            }
        } catch (\Throwable $e) {
            // The settle's PaymentOutcome already went out; the lost state is a PaymentFault (record): a person looks.
            $sale->fault(PaymentFault::RECORD, $e);
        }
    }

    /**
     * Whether $sensitive marks this request (or MCP params) as one whose answer is not kept. Fail-closed: an
     * exception, or any answer but FALSE, is sensitive. It only stops this seller keeping a copy; it says
     * nothing about what other layers (an app cache) do with the same input.
     */
    public static function sensitive(?\Closure $sensitive, ServerRequestInterface|array $subject): bool
    {
        if (null === $sensitive) {
            return false;
        }
        try {
            return false !== $sensitive($subject);
        } catch (\Throwable) {
            return true;
        }
    }

    private function replay(array $state): ResponseInterface
    {
        return Sale::restore($state['response'], $this->responses, $this->streams)
          ->withHeader(PaymentHeaders::RESPONSE, PaymentHeaders::encode($state['receipt']));
    }

    /**
     * Settle failed: a 402 with the failed SettlementResponse, as
     * transports-v2/http.md asks; 502 when the facilitator was unreachable.
     */
    private function failed(array $accepts, array $resource, array $receipt): ResponseInterface
    {
        $response = match ($receipt['errorReason']) {
            Sale::UNAVAILABLE => $this->json(502, ['error' => Sale::UNAVAILABLE]),
            // The ledger refused the transaction's reference, so it was never sent: as a refused intention.
            'reference_unavailable' => $this->json(503, ['error' => 'ledger_unavailable']),
            default => $this->required($accepts, $resource, $receipt['errorReason']),
        };

        return $response->withHeader(PaymentHeaders::RESPONSE, PaymentHeaders::encode($receipt));
    }

    private function required(array $accepts, array $resource, string $error): ResponseInterface
    {
        $body = ['x402Version' => 2, 'error' => $error, 'resource' => $resource, 'accepts' => array_values($accepts)];

        return $this->json(402, $body)->withHeader(PaymentHeaders::REQUIRED, PaymentHeaders::encode($body));
    }

    private function json(int $status, array $body): ResponseInterface
    {
        return $this->responses->createResponse($status)
          ->withHeader('Content-Type', 'application/json')
          ->withBody($this->streams->createStream(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }
}
