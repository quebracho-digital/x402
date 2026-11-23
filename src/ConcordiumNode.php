<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * What a Concordium facilitator needs from a node, in the shapes the checks use.
 *
 * GrpcConcordiumNode speaks to a node; a test or another transport implements
 * this to answer without one.
 */
interface ConcordiumNode
{
    /**
     * The account's balance and signing keys at the last finalized block.
     *
     * @return ?array{amount: int, available: int, threshold: int, credentials: array<int, array{threshold: int, keys: array<int, string>}>}
     *                                                                                                                                       NULL when the account does not exist
     *
     * @throws FacilitatorException the node cannot be reached or answered something else
     */
    public function accountInfo(string $address): ?array;

    /**
     * The next sequence number for an account, counting transactions the node knows and has not finalized.
     *
     * @throws FacilitatorException
     */
    public function nextNonce(string $address): int;

    /**
     * Submits a signed V1 transfer and returns its hash, lower-case hex without 0x.
     *
     * @param array $tx the JSON `signedTransaction`, sponsor signature included
     *
     * @throws FacilitatorException
     * @throws GrpcException        the node rejected the transaction
     */
    public function send(array $tx): string;

    /**
     * The status of a transaction: whether it is finalized, and the transfer it made.
     *
     * @return array{finalized: bool, success: bool, amount: ?int, receiver: ?string, transaction: string}
     *
     * @throws FacilitatorException
     */
    public function status(string $hash): array;
}
