<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Clock\ClockInterface;

/**
 * Reads the time from whatever clock a class was given: a PSR-20 clock, a closure that returns Unix
 * seconds (what the library took before PSR-20, still accepted), or none (the system clock).
 *
 * @internal
 */
final class Clock
{
    /**
     * @param ClockInterface|(\Closure(): int)|null $clock
     */
    public static function now(ClockInterface|\Closure|null $clock): int
    {
        return match (true) {
            null === $clock => time(),
            $clock instanceof ClockInterface => $clock->now()->getTimestamp(),
            default => $clock(),
        };
    }
}
