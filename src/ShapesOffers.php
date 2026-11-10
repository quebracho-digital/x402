<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Optional for a Scheme: its offers are not the default ones (a price in dollars, any validity up to the
 * buyer's limit, settled after the handler). Networks asks it whenever an offer is built on one of the
 * scheme's networks, before anything else is checked.
 */
interface ShapesOffers
{
    /**
     * What this scheme adds to an offer's `extra` (a payment flow, say), or an exception when such an offer
     * must not be made at all.
     *
     * @param int  $timeout the offer's maxTimeoutSeconds
     * @param bool $inToken whether the price was given in the token's own units (Networks::requirementsInToken)
     *                      and not in dollars (Networks::requirements)
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException naming what is wrong with the offer
     */
    public function offerExtra(string $network, int $timeout, bool $inToken): array;
}
