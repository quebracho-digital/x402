<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/**
 * Runs one queued job: queued > running > ready (or failed_ours). Call it after the `_start` response went
 * out (in Symfony, kernel.terminate; or a queue worker), never before the job row is durable.
 */
final class JobRunner
{
    /** @var array<string, JobWork> */
    private array $byTool = [];

    /**
     * @param iterable<JobWork> $works every job tool's work
     * @param ?\Closure(): int  $clock
     * @param ?JobHolds         $holds captures or voids a job paid with a hold once its work ended; without it, such a
     *                                 job stays `capturing` (or its hold open) until the cron's JobHolds::sweep
     */
    public function __construct(
        private readonly JobStore $jobs,
        iterable $works,
        private readonly ?\Closure $clock = null,
        private readonly ?JobHolds $holds = null,
    ) {
        foreach ($works as $work) {
            $this->byTool[$work::start()] = $work;
        }
    }

    /** The work a `_start` tool sells, or NULL when it sells none. */
    public function work(string $tool): ?JobWork
    {
        return $this->byTool[$tool] ?? null;
    }

    /**
     * @return Job the job as it ended (or as it was, when it was not queued: another runner took it)
     */
    public function run(string $id): Job
    {
        try {
            // The claim on the work: of two runners, one moves queued > running, the other gets IllegalTransition.
            $job = $this->jobs->update($id, fn (Job $j) => $j->to(Job::RUNNING, $this->now()));
        } catch (IllegalTransition) {
            return $this->jobs->get($id) ?? throw new \OutOfBoundsException("No job $id.");
        }
        $work = $this->work($job->tool);
        try {
            if (null === $work) {
                // ponytail: unreachable when the `_start` tool refuses to sell work it has none for (JobRunner::work).
                throw new JobFailed('provider_error');
            }
            $result = $work->run($job->params ?? []);
            // Paid with a hold: the result waits in `capturing` until the escrow paid us.
            $end = fn (Job $j) => $j->to(null === $j->hold ? Job::READY : Job::CAPTURING, $this->now(), $result);
        } catch (JobFailed $e) {
            $end = fn (Job $j) => $j->to(Job::FAILED_OURS, $this->now(), reason: $e->reason);
        } catch (\Throwable) {
            // The input was checked before the deposit was taken: whatever else goes wrong is ours.
            $end = fn (Job $j) => $j->to(Job::FAILED_OURS, $this->now(), reason: 'provider_error');
        }
        try {
            $ended = $this->jobs->update($id, $end);
        } catch (IllegalTransition) {
            // The janitor gave up on it while it ran (failed_ours, final): the late answer is dropped.
            $ended = $this->jobs->get($id) ?? throw new \OutOfBoundsException("No job $id.");
        }

        // Capture (done) or void (failed by us) the hold now, not at the next cron: the buyer is polling.
        return null === $this->holds ? $ended : $this->holds->finish($ended);
    }

    private function now(): int
    {
        return null === $this->clock ? time() : ($this->clock)();
    }
}
