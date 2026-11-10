<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client that pays x402 402s and retries once, within a budget.
 *
 * Wrap any PSR-18 client with it, e.g. the one an MCP HTTP transport uses,
 * and paid tools just work for the agent behind it.
 *
 * The wrapped client must not follow redirects: one to another host would
 * hand it the signed payment, which it could settle without delivering.
 */
final class PayingHttpClient implements ClientInterface
{
    /**
     * @param Scheme[]                  $schemes
     *                                             The schemes this buyer can pay with, each holding its key
     * @param Budget                    $budget
     *                                             Refuses whatever would exceed the spending cap
     * @param ?\Closure                 $onPaid
     *                                             fn(array $req, array $receipt): void — e.g. to log what was spent.
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
     *                                             While its file exists nothing is signed: the offer is refused as payments_paused and the caller sees the 402 itself. NULL: no stop switch
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly array $schemes,
        private readonly Budget $budget,
        private readonly ?\Closure $onPaid = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?\Closure $onRefused = null,
        private readonly ?OfacSdn $ofac = null,
        private readonly ?Stop $stop = null,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->client->sendRequest($request);
        $header = $response->getHeaderLine(PaymentHeaders::REQUIRED);
        if (402 !== $response->getStatusCode() || '' === $header) {
            return $response;
        }
        try {
            $required = PaymentHeaders::decode($header);
        } catch (InvalidPaymentException) {
            // Not an offer we can read: the caller sees the 402 itself.
            return $response;
        }
        if ($this->stop?->engaged()) {
            // Stopped by hand: nothing is reserved or signed, the caller sees the 402 itself.
            Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::REFUSED, [], Stop::REASON));
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
                Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::REFUSED, is_array($req) ? $req : [], $reason));
                try {
                    $this->onRefused?->__invoke(is_array($req) ? $req : [], $reason);
                } catch (\Throwable) {
                    // ponytail: a failing refusal callback is dropped; the 402 still goes to the caller.
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
                // The same request, paid: the body is read again from the start.
                if ($request->getBody()->isSeekable()) {
                    $request->getBody()->rewind();
                }
                $signed = $request->withHeader(PaymentHeaders::SIGNATURE, PaymentHeaders::encode($payment));
            } catch (\Throwable $e) {
                // Never left this process: a failed signature spends nothing.
                $this->budget->release($req);
                Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::RELEASED, $req, error: $e));

                throw $e;
            }
            Events::report($this->events, PurchaseIntent::of($scheme, $payment, $req));
            try {
                $paid = $this->client->sendRequest($signed);
            } catch (\Throwable $e) {
                // It may have reached the seller: kept as unsettled, to be reclaimed once expired.
                $this->budget->unsettled($req, $payment);
                Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::UNSETTLED, $req, error: $e));

                throw $e;
            }
            $receipt = $paid->getHeaderLine(PaymentHeaders::RESPONSE);
            try {
                $receipt = '' === $receipt ? null : PaymentHeaders::decode($receipt);
            } catch (InvalidPaymentException) {
                $receipt = null;
            }
            if (($receipt['success'] ?? null) === true) {
                Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::PAID, $req, receipt: $receipt));
                // The payment happened: a callback that fails must not cost the paid answer.
                try {
                    $this->onPaid?->__invoke($req, $receipt);
                } catch (\Throwable) {
                    // ponytail: errors of a logging callback are dropped, as Sale::outcome drops them.
                }
            } else {
                $this->budget->unsettled($req, $payment);
                $reason = is_array($receipt) && is_string($receipt['errorReason'] ?? null) ? $receipt['errorReason'] : null;
                Events::report($this->events, new PurchaseOutcome(PurchaseOutcome::UNSETTLED, $req, $reason, is_array($receipt) ? $receipt : null));
            }

            return $paid;
        }

        // Nothing we can or may pay: the caller sees the 402 itself.
        return $response;
    }
}
