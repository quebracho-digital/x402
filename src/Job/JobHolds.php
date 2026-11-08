<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

use Quebracho\X402\AuthCapture;
use Quebracho\X402\EvmAuthCapture;
use Quebracho\X402\HoldOperator;
use Quebracho\X402\Reconciler;

/**
 * Finishes the hold of a job paid with `auth-capture` (Job::$hold): captures it when the work is done,
 * voids it when the work failed by our fault. Decided by the maintainer (2026-11-08): capture as soon as
 * the result is ready, and a failure of ours voids the whole hold, so the buyer pays nothing.
 *
 * The escrow is the record, not this store. Whatever got lost on the way (a crash after the send, a node
 * that timed out), reconcile() reads paymentState and acts on what the chain says:
 *
 *   refundable > 0              captured: capturing > ready (and the result goes out, free, to its payer)
 *   capturable > 0              still held: capture it again (capturing), or void it (failed_ours)
 *   both 0, collected           it went back to the buyer: voided by us, or reclaimed by the buyer after
 *                               captureDeadline. capturing > failed_ours / capture_missed
 *   not collected               never held: not for this class (JobHolds::deposit tells JobReconciler)
 *
 * The chain also keeps a capture and a void from both happening: once one ran, the other reverts. A capture
 * or void sent less than MIN_AGE ago is left alone (it may be in a block already), so two crons do not
 * send the same call twice; a second send would only cost gas, never money.
 */
final class JobHolds
{
    /** Seconds a capture or void we sent gets to land before the cron reads the escrow and tries again. */
    public const MIN_AGE = 60;

    /**
     * Seconds of hold a job needs beyond its work: the escrow refuses a capture from captureDeadline on,
     * and a late capture would race the buyer's reclaim. The `_start` offer's captureDeadline is now +
     * the work's longest run (JobJanitor::STALE_AFTER) + this.
     */
    public const CAPTURE_WINDOW = 3600;

    /** @var array<string, HoldOperator> */
    private array $operators = [];

    /**
     * @param iterable<HoldOperator> $operators one per network a hold can be on
     * @param ?\Closure(): int       $clock
     */
    public function __construct(
        private readonly JobStore $jobs,
        iterable $operators,
        private readonly ?\Closure $clock = null,
    ) {
        foreach ($operators as $operator) {
            $this->operators[$operator->network()] = $operator;
        }
    }

    /**
     * What a job keeps about an `auth-capture` deposit: the escrow's PaymentInfo (enough to capture, void or
     * read it; no signature), or NULL for any other payment. Only after the scheme verified it.
     *
     * @return ?array{network: string, info: array<string, string|int>, end: null, transaction: null, sentAt: null}
     */
    public static function of(array $payment, array $req): ?array
    {
        if (AuthCapture::SCHEME !== ($req['scheme'] ?? null)) {
            return null;
        }

        return ['network' => (string) $req['network'], 'info' => EvmAuthCapture::paymentInfoOf($payment, $req), 'end' => null, 'transaction' => null, 'sentAt' => null];
    }

    /**
     * The captureDeadline a `_start` offer should carry for work that runs at most $longestRun seconds.
     */
    public static function captureDeadline(int $now, int $longestRun = JobJanitor::STALE_AFTER): int
    {
        return $now + $longestRun + self::CAPTURE_WINDOW;
    }

    /**
     * Whether a `settling` hold job's deposit is on hold (true), never can be (false), or unknown (NULL). The
     * escrow marks a payment collected only when its operator (us) authorized it, so "collected" is proof
     * that the money is in the escrow for us; and the escrow refuses to authorize after preApprovalExpiry
     * (the signature's validBefore), so "not collected" after that is final.
     */
    public function deposit(Job $job, int $now): ?bool
    {
        $operator = $this->operator($job);
        if (null === $operator || null === $job->hold) {
            return null;
        }
        try {
            if ($operator->state($job->hold['info'])['collected']) {
                return true;
            }
        } catch (\Throwable) {
            return null;
        }

        return $now > (int) $job->hold['info']['preApprovalExpiry'] + Reconciler::GRACE ? false : null;
    }

    /**
     * Sends the capture (a capturing job) or the void (a failed_ours job with an open hold), right after the
     * work ended. Whatever the answer, the job is left as the chain will confirm: a definite answer moves
     * it, an unclear one leaves it to reconcile().
     *
     * @return Job the job as it is now
     */
    public function finish(Job $job): Job
    {
        $operator = $this->operator($job);
        if (null === $operator || null === $job->hold || null !== $job->hold['end']) {
            return $job;
        }

        return match ($job->state) {
            Job::CAPTURING => $this->capture($operator, $job),
            Job::FAILED_OURS => $this->void($operator, $job),
            default => $job,
        };
    }

    /**
     * One hold job whose capture or void may not have happened: reads the escrow and moves it on.
     *
     * @return Job the job as it is now
     */
    public function reconcile(Job $job): Job
    {
        $operator = $this->operator($job);
        $hold = $job->hold;
        if (null === $operator || null === $hold || null !== $hold['end'] || !\in_array($job->state, [Job::CAPTURING, Job::FAILED_OURS], true)) {
            return $job;
        }
        if (null !== $hold['sentAt'] && $hold['sentAt'] > $this->now() - self::MIN_AGE) {
            return $job;
        }
        try {
            $state = $operator->state($hold['info']);
        } catch (\Throwable) {
            return $job;
        }
        if (!$state['collected']) {
            // ponytail: a job past settling always has its hold collected; a node lagging behind says otherwise.
            return $job;
        }
        if ('0' !== $state['refundable']) {
            return $this->ended($job, 'captured');
        }
        if ('0' !== $state['capturable']) {
            return Job::CAPTURING === $job->state ? $this->capture($operator, $job) : $this->void($operator, $job);
        }

        return $this->ended($job, 'released');
    }

    /**
     * Every hold job a lost answer left unfinished, from the cron.
     *
     * @return list<array{job: string, was: string, result: string}>
     */
    public function sweep(): array
    {
        $out = [];
        foreach ($this->jobs->all() as $job) {
            if (null === $job->hold || null !== $job->hold['end'] || !\in_array($job->state, [Job::CAPTURING, Job::FAILED_OURS], true)) {
                continue;
            }
            $now = $this->reconcile($job);
            $out[] = ['job' => $job->id, 'was' => $job->state, 'result' => null !== ($now->hold['end'] ?? null) ? $now->state.'/'.$now->hold['end'] : 'unknown'];
        }

        return $out;
    }

    private function capture(HoldOperator $operator, Job $job): Job
    {
        $info = $job->hold['info'] ?? [];
        // Past the deadline the escrow refuses (and the buyer may be reclaiming): read it, do not send.
        if ($this->now() >= (int) $info['authorizationExpiry'] - 2 * EvmAuthCapture::SLACK) {
            return $job;
        }
        $sent = $operator->capture($info, null, $this->reference($job));

        return $sent['success'] ? $this->ended($job, 'captured') : $this->jobs->get($job->id) ?? $job;
    }

    private function void(HoldOperator $operator, Job $job): Job
    {
        $sent = $operator->void($job->hold['info'] ?? [], $this->reference($job));

        return $sent['success'] ? $this->ended($job, 'released') : $this->jobs->get($job->id) ?? $job;
    }

    /**
     * Writes the transaction on the job before it is sent: a crash after the send still leaves the job
     * marked as "something was sent", so the next reconcile waits MIN_AGE before acting again.
     */
    private function reference(Job $job): \Closure
    {
        return function (string $transaction) use ($job): bool {
            $this->jobs->update($job->id, fn (Job $j) => $j->withHold(['transaction' => $transaction, 'sentAt' => $this->now()]));

            return true;
        };
    }

    /**
     * How the hold ended, and where that takes the job: a capture makes it ready, a hold that went back to
     * the buyer before we captured fails it as ours (capture_missed). Under the store's lock, from the job as
     * it is now.
     */
    private function ended(Job $job, string $end): Job
    {
        try {
            return $this->jobs->update($job->id, function (Job $j) use ($end): Job {
                $j = $j->withHold(['end' => $end]);

                return match (true) {
                    Job::CAPTURING === $j->state && 'captured' === $end => $j->to(Job::READY, $this->now()),
                    Job::CAPTURING === $j->state => $j->to(Job::FAILED_OURS, $this->now(), reason: 'capture_missed'),
                    default => $j,
                };
            });
        } catch (IllegalTransition|\OutOfBoundsException) {
            return $this->jobs->get($job->id) ?? $job;
        }
    }

    private function operator(Job $job): ?HoldOperator
    {
        return null === $job->hold ? null : $this->operators[$job->hold['network']] ?? null;
    }

    private function now(): int
    {
        return null === $this->clock ? time() : ($this->clock)();
    }
}
