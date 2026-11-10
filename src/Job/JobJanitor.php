<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/**
 * Housekeeping by time alone, from a cron every minute:
 *
 * - sweep(): gives up on work nobody is doing. A `running` job whose runner died (a PHP-FPM worker killed,
 *   a deploy) or a `queued` one no runner took, untouched for $staleAfter seconds, becomes failed_ours /
 *   our_timeout, so its buyer stops waiting and `_result` costs nothing. Call one() for a single job when its
 *   buyer asks (a `_result`, a `_status`), so a buyer is not left waiting on a cron that does not run.
 * - orphans(): the `queued` jobs no request runs (the retry that queued one found no free slot, or the
 *   reconciler queued it): the cron runs them in a free slot, long before they would be stale.
 * - forget(): the TTLs on what a job holds: a delivered result after Job::RESULT_TTL, a result nobody bought
 *   after Job::READY_TTL, the buyer's input on a `settling` row nobody could resolve after Job::PARAMS_TTL.
 *   Only this cron run deletes buyer text by time: without the cron it stays on disk.
 *
 * Never moves `settling` (whether the deposit moved is the reconciler's question, JobReconciler)
 * nor `delivering` (the `_result` payment may be on chain).
 */
final class JobJanitor
{
    /** Seconds without a change before a queued or running job counts as abandoned: longer than any work (40 s). */
    public const int STALE_AFTER = 300;
    /** Seconds a queued job waits for its own request's runner before the cron runs it instead. */
    public const int ORPHAN_AFTER = 30;

    public function __construct(private readonly JobStore $jobs)
    {
    }

    /**
     * @return list<string> the ids it failed
     */
    public function sweep(int $now, int $staleAfter = self::STALE_AFTER): array
    {
        $failed = [];
        foreach ($this->jobs->all() as $job) {
            if ($this->stale($job, $now, $staleAfter) && Job::FAILED_OURS === $this->one($job, $now, $staleAfter)->state) {
                $failed[] = $job->id;
            }
        }

        return $failed;
    }

    /**
     * One job, given up on if it is stale.
     *
     * @return Job the job as it is now
     */
    public function one(Job $job, int $now, int $staleAfter = self::STALE_AFTER): Job
    {
        if (!$this->stale($job, $now, $staleAfter)) {
            return $job;
        }
        try {
            // Checked again under the lock: the runner may have finished since it was read.
            return $this->jobs->update($job->id, fn (Job $j) => $this->stale($j, $now, $staleAfter) ? $j->to(Job::FAILED_OURS, $now, reason: 'our_timeout') : throw new IllegalTransition('no longer stale'));
        } catch (IllegalTransition|\OutOfBoundsException) {
            return $this->jobs->get($job->id) ?? $job;
        }
    }

    /**
     * @return list<string> the queued jobs untouched for $after seconds, oldest first
     */
    public function orphans(int $now, int $after = self::ORPHAN_AFTER): array
    {
        $queued = [];
        foreach ($this->jobs->all() as $job) {
            if (Job::QUEUED === $job->state && $job->updatedAt <= $now - $after) {
                $queued[$job->id] = $job->updatedAt;
            }
        }
        asort($queued);

        return array_keys($queued);
    }

    /**
     * @return list<string> the ids whose result or input it dropped
     */
    public function forget(int $now): array
    {
        $forgotten = [];
        foreach ($this->jobs->all() as $job) {
            if (!$this->expired($job, $now)) {
                continue;
            }
            try {
                $this->jobs->update($job->id, fn (Job $j) => $this->expired($j, $now) ? $j->forget() : throw new IllegalTransition('changed meanwhile'));
                $forgotten[] = $job->id;
            } catch (IllegalTransition|\OutOfBoundsException) {
            }
        }

        return $forgotten;
    }

    private function stale(Job $job, int $now, int $staleAfter): bool
    {
        return \in_array($job->state, [Job::QUEUED, Job::RUNNING], true) && $job->updatedAt <= $now - $staleAfter;
    }

    private function expired(Job $job, int $now): bool
    {
        return match ($job->state) {
            Job::DELIVERED => null !== $job->result && $job->updatedAt + Job::RESULT_TTL <= $now,
            Job::READY => null !== $job->result && $job->updatedAt + Job::READY_TTL <= $now,
            Job::SETTLING => null !== $job->params && $job->createdAt + Job::PARAMS_TTL <= $now,
            default => false,
        };
    }
}
