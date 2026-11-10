<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * A deploy check for a PaymentLedger: takes and frees one claim, so a directory that cannot be written or a
 * Redis that does not answer fails the deploy, not the first paid request.
 */
final class LedgerCheck
{
    /**
     * @throws LedgerUnavailableException when the store does not answer
     * @throws \RuntimeException          when it answers but does not take a claim
     */
    public static function run(PaymentLedger $ledger): void
    {
        $key = 'x402-check-'.bin2hex(random_bytes(4));
        if (!$ledger->claim($key, 1)) {
            throw new \RuntimeException(\sprintf('The x402 ledger (%s) does not take a claim.', $ledger::class));
        }
        $ledger->release($key);
    }
}
