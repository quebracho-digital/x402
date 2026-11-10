<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * What each payment has become, kept across requests (and processes, when the store is shared).
 *
 * A state is an array: `status` is 'claimed' (in progress), 'settling' (intention written before a settle
 * is sent), 'handling' (upfront: intention written before the handler runs), 'settled' (charged, no response
 * kept), 'delivered' (charged, response kept), or 'pending' (may have charged, not confirmed). Settled and
 * pending states carry 'transaction', 'paymentId', 'network' and 'receipt' when known; a response
 * is `['status' => int, 'headers' => array<string,string>, 'body' => string]` (Settlement::restore() rebuilds it).
 *
 * Every state expires after its TTL in seconds.
 */
interface PaymentLedger
{
    /**
     * Stores a 'claimed' state unless the key already holds a live one. Atomic: of N concurrent
     * calls exactly one returns TRUE.
     */
    public function claim(string $key, int $ttl): bool;

    /**
     * The stored state, or NULL when there is none (or it expired).
     *
     * @return ?array<string, mixed>
     */
    public function get(string $key): ?array;

    /**
     * Replaces the state and renews the TTL.
     *
     * @param array<string, mixed> $state
     */
    public function put(string $key, array $state, int $ttl): void;

    /**
     * Deletes the key, and its open entry (open()): a payment that charged nothing is good again.
     */
    public function release(string $key): void;

    /**
     * Lists the key as open (to reconcile against the chain), with $ref: the minimal data to ask the chain
     * about it (network, asset, from, nonce, payTo, amount, validBefore, transaction, paymentId, status), never
     * a signature or a body. The entry lives $ttl seconds, apart from the state's own TTL. The ledger sets
     * `opened` (Unix time) on the first open of a key (the one given in $ref, else now) and keeps it when the
     * key is opened again.
     *
     * @param array<string, mixed> $ref
     *
     * @throws LedgerUnavailableException when the store cannot write it
     */
    public function open(string $key, array $ref, int $ttl): void;

    /**
     * Up to $limit open entries, key => ref (with `opened`); expired ones are left out.
     *
     * @return array<string, array<string, mixed>>
     */
    public function openKeys(int $limit): array;

    /**
     * Removes the key from the open entries; its state is not touched.
     */
    public function close(string $key): void;
}
