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
     *                                           The schemes this buyer can pay with, each holding its key
     * @param Budget                    $budget
     *                                           Refuses whatever would exceed the spending cap
     * @param ?\Closure                 $onPaid
     *                                           fn(array $req, array $receipt): void — e.g. to log what was spent.
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
        private readonly ?\Closure $onPaid = null,
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?\Closure $onRefused = null,
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
            // The same request, paid: the body is read again from the start.
            if ($request->getBody()->isSeekable()) {
                $request->getBody()->rewind();
            }
            $paid = $this->client->sendRequest($request->withHeader(PaymentHeaders::SIGNATURE, PaymentHeaders::encode($payment)));
            $receipt = $paid->getHeaderLine(PaymentHeaders::RESPONSE);
            try {
                $receipt = '' === $receipt ? null : PaymentHeaders::decode($receipt);
            } catch (InvalidPaymentException) {
                $receipt = null;
            }
            if (($receipt['success'] ?? null) === true) {
                $this->onPaid?->__invoke($req, $receipt);
            } else {
                $this->budget->unsettled($req, $payment);
            }

            return $paid;
        }

        // Nothing we can or may pay: the caller sees the 402 itself.
        return $response;
    }
}
