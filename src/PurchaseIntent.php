<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The buyer signed a payment and is about to send it, dispatched (PSR-14) by PayingHttpClient and
 * PayingMcpClient: a signed authorization is money the seller can settle on its own, so a process that dies
 * after the send still leaves this line, where PurchaseOutcome would leave nothing. The buyer's PaymentIntent.
 *
 * key: Settlement::key, the same key the seller's ledger uses, so both sides can be matched. payment: network
 * and from only, never the signature. req: network, payTo, amount, asset.
 */
final class PurchaseIntent
{
    public function __construct(
        public readonly string $key,
        public readonly array $payment,
        public readonly array $req,
    ) {
    }

    /**
     * @internal
     */
    public static function of(Scheme $scheme, array $payment, array $req): self
    {
        return new self(
            Settlement::key($payment, [$scheme]),
            ['network' => $req['network'] ?? null, 'from' => $scheme->payer($payment)],
            array_intersect_key($req, array_flip(['network', 'payTo', 'amount', 'asset'])),
        );
    }
}
