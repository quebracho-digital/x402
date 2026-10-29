<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * What became of a payment, dispatched (PSR-14) once per paid request that
 * carried one: for logs, metrics and the seller's own records.
 *
 * status: settled (money moved, delivered), not_settled (ran, settle failed:
 * withheld), failed (the handler delivered nothing: nothing charged),
 * rejected (refused before running: bad, screened, replayed, unavailable).
 */
final class PaymentOutcome
{
    public function __construct(
        public readonly string $status,
        public readonly array $payment,
        public readonly array $req,
        public readonly ?int $handlerStatus = null,
        public readonly ?array $receipt = null,
        public readonly ?string $reason = null,
    ) {
    }
}
