<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Optional for a Scheme: what the chain spends once in a payment, so the same payment signed twice (or
 * written in another spelling) is one payment to the seller's claim and ledger (Settlement::key).
 *
 * A scheme without it is identified by its whole payload: two payloads that differ in one byte are two
 * payments, which is only right when the chain agrees.
 *
 * A scheme of your own that implements it must be among the schemes given to Settlement::key() wherever a key
 * is computed outside the middlewares (they pass theirs); the built-in schemes are always known.
 */
interface SpendsOnce
{
    /**
     * The parts that identify the payment (network, payer, nonce…), in one spelling each, or NULL when the
     * payload cannot be read (it is then identified by its whole payload, and verify() refuses it anyway).
     *
     * @return ?list<string|int>
     */
    public function spent(array $payment): ?array;
}
