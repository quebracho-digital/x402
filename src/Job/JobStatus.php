<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/**
 * What `<tool>_status` answers: a closed set of states and reasons, whether asking again can
 * change the answer, and when to ask. Free, so it must leak nothing: a job someone else paid for answers
 * exactly like a job that does not exist.
 *
 * Known limit: the payer address is public, so it is not proof of who is asking.
 * The real secret is the 128-bit jobId. Proving the asker would take a signature (e.g. EIP-191 over the jobId),
 * which this leaves out.
 */
final class JobStatus
{
    public const UNKNOWN = 'unknown';
    /** Ready, but nobody bought the result within Job::READY_TTL: it was deleted, there is nothing to sell. */
    public const EXPIRED = 'expired';

    /** state => [reason, retryable, retryAfter seconds]. */
    private const VIEW = [
        Job::SETTLING => ['deposit_settling', true, 10],
        Job::QUEUED => ['queued', true, 5],
        Job::RUNNING => ['running', true, 5],
        // A hold job: done, our capture is on its way to the escrow. The result follows, free.
        Job::CAPTURING => ['payment_capturing', true, 10],
        Job::READY => ['result_ready', false, null],
        Job::DELIVERING => ['result_settling', true, 10],
        Job::DELIVERED => ['delivered', false, null],
        self::EXPIRED => ['result_expired', false, null],
        Job::FAILED_OURS => [null, false, null],
        self::UNKNOWN => ['unknown_job', false, null],
    ];

    /**
     * @return array{jobId: string, state: string, reason: ?string, retryable: bool, retryAfter: ?int}
     */
    public static function of(string $jobId, ?Job $job, string $payer): array
    {
        $state = null !== $job && $job->paidBy($payer) ? ($job->expired() ? self::EXPIRED : $job->state) : self::UNKNOWN;
        [$reason, $retryable, $after] = self::VIEW[$state];

        return ['jobId' => $jobId, 'state' => $state, 'reason' => $reason ?? $job?->reason, 'retryable' => $retryable, 'retryAfter' => $after];
    }
}
