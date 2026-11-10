<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/**
 * How many jobs may run at once: the server's capacity (a model's rate limit, CPU). A deposit takes a free
 * slot before it settles, so a busy server refuses the sale instead of selling work it cannot start.
 *
 * The library ships FileJobSlots (one host). An application with a lock service of its own (Symfony Lock,
 * Redis) adapts it in a few lines: take() answers a JobSlot whose release() frees the lock.
 */
interface JobSlots
{
    /**
     * A free slot, taken, or NULL when every slot is busy. Never waits.
     *
     * @throws \RuntimeException when the store behind the slots cannot answer
     */
    public function take(): ?JobSlot;
}
