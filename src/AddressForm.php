<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Optional for a Scheme: how its chain writes addresses and transaction ids. Used to refuse a payTo that is not
 * an address when an offer is built (Networks::requirements), and to compare what a facilitator or a node
 * reports with what was signed. A scheme without it gets no check on payTo and a byte-for-byte comparison.
 */
interface AddressForm
{
    public function valid(string $address): bool;

    /**
     * The one spelling an offer carries (EIP-55 on EVM); the address itself where the chain has only one.
     */
    public function spell(string $address): string;

    /**
     * Whether two spellings name the same address or transaction (hex in any case on EVM; base58 is exact).
     */
    public function same(string $a, string $b): bool;
}
