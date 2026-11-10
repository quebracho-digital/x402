<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/**
 * A work's failure that is ours, by the fixed list (Job::FAILURES): the job becomes failed_ours and its
 * `_result` costs nothing. Anything else a work throws is filed as provider_error (JobRunner).
 */
final class JobFailed extends \RuntimeException implements \Quebracho\X402\X402Exception
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        if (!\in_array($reason, Job::FAILURES, true)) {
            throw new \LogicException("$reason is not in Job::FAILURES.");
        }
        parent::__construct("Job failed: $reason.", 0, $previous);
    }
}
