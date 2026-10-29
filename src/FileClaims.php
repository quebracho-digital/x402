<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Claims each payment once, durably and atomically, on one host.
 *
 * fopen 'x' is O_CREAT|O_EXCL: of N concurrent claims exactly one wins,
 * across processes and restarts.
 *
 * Swap for a shared store (DB unique key, Redis SET NX) on several hosts.
 */
final class FileClaims
{
    public function __construct(private readonly string $dir)
    {
    }

    public function __invoke(string $key): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            // Cannot record it, so cannot prove it is fresh: refuse.
            return false;
        }
        $handle = @fopen($this->dir.'/'.$key, 'x');
        if (false === $handle) {
            return false;
        }
        fclose($handle);

        return true;
    }

    /**
     * Frees a claim whose payment charged nothing: the middlewares' $release.
     */
    public function release(string $key): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $key)) {
            @unlink($this->dir.'/'.$key);
        }
    }

    /**
     * Deletes claims older than $seconds (past every validBefore cap), e.g. on cron.
     */
    public function prune(int $seconds): int
    {
        $n = 0;
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            if (filemtime($file) < time() - $seconds && @unlink($file)) {
                ++$n;
            }
        }

        return $n;
    }
}
