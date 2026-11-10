<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Optional for a Scheme, and required to buy with it: whether a buyer may sign a seller's offer. The seller
 * wrote the offer, so nothing in it is trusted. Budget asks this after its own checks (a cap for the network, a
 * plain amount, a short validity, the token it knows); the scheme checks what only it can read: that payTo
 * is an address in its one spelling, and that whatever else gets signed (an EIP-712 domain, a fee payer) is
 * the token's own. A scheme without it signs nothing through Budget: every offer is refused (not_allowed).
 */
interface VetsOffers
{
    /**
     * @param array $req   one entry of a 402's `accepts`
     * @param array $token the buyer's own settings for $req['network'] (Networks::find)
     */
    public function signable(array $req, array $token): bool;
}
