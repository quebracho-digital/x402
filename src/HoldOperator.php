<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * What a seller needs to finish an `auth-capture` hold it operates: read how it stands, capture it, void it.
 * AuthCaptureOperator is the on-chain one; Job\JobHolds depends on this, so it can be tested without a node.
 */
interface HoldOperator
{
    /** The CAIP-2 network it operates on. */
    public function network(): string;

    /**
     * @return array{collected: bool, capturable: string, refundable: string}
     *
     * @throws \RuntimeException the escrow could not be read
     */
    public function state(array $info): array;

    /**
     * @param ?\Closure $reference fn(string $transaction): bool, called with the hash before the send
     *
     * @return array{success: bool, transaction: string, errorReason?: string}
     */
    public function capture(array $info, ?string $amount = null, ?\Closure $reference = null): array;

    /**
     * @param ?\Closure $reference fn(string $transaction): bool, called with the hash before the send
     *
     * @return array{success: bool, transaction: string, errorReason?: string}
     */
    public function void(array $info, ?\Closure $reference = null): array;
}
