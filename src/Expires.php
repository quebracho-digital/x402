<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Optional for a Scheme: its payments carry an expiry the seller can read offline (EIP-3009's validBefore).
 *
 * With it the seller keeps a payment's claim and state only as long as the payment can still move, and can
 * sell slow work (a middleware's $duration): a payment that would expire before the work ends is refused
 * before anything runs. A scheme without it cannot sell work that declares a duration (unsupported_scheme).
 */
interface Expires
{
    /**
     * The Unix time after which the chain refuses this payment, or NULL when the payload has none readable.
     */
    public function expiresAt(array $payment): ?int;

    /**
     * Why this payment cannot wait $seconds before it is settled (the scheme's own reason code, with its own
     * margin and clock), or NULL when it can. Called only after verify() passed.
     */
    public function tooShortFor(array $payment, int $seconds): ?string;
}
