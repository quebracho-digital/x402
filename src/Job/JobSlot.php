<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/**
 * One taken concurrency slot (JobSlots::take()), held until release().
 */
interface JobSlot
{
    /**
     * Frees the slot. Calling it twice is harmless.
     */
    public function release(): void;
}
