<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

use Psr\Clock\ClockInterface;
use Quebracho\X402\Clock;
use Quebracho\X402\Scheme;
use Quebracho\X402\X402McpMiddleware;
use Quebracho\X402\X402Middleware;

/**
 * The delivery: a `_result` call of a job tool, which buys a ready job's result, and the free
 * `_status` that tells the buyer when to buy it.
 *
 * The result is paid in the default flow: the tool answers, then the payment settles. JobDesk routes the
 * middleware's hooks here for a `_result`, in this order:
 *
 *   payer()         $payer, before the claim: only the deposit's payer, only a ready job. A second payment
 *                   while one is settling is refused before it is claimed (decision 7e), a failed_ours job is
 *                   refused (it costs nothing). A job stuck where nobody will move it is looked at first:
 *                   stale work is failed (ours, so the result is free), a `delivering` one asks the ledger how
 *                   its payment ended (JobReconciler)
 *   deliver()       the tool: ready > delivering, atomically (of two concurrent payments one delivers), with
 *                   the payment's reference on the job, so a lost settle answer can be looked up later
 *   beforeSettle()  $settle, just before the facilitator. From here the money may move
 *   afterSettle()   the facilitator answered: delivered, or back to ready when nothing moved; when it may have
 *                   moved, left delivering for JobReconciler
 *   afterResponse() kernel.terminate: a job handed over but never settled is ready again
 *
 * And the free re-read (decision 7e): for Job::RESULT_TTL after it was paid, the payer may call `_result`
 * again with a fresh signature. The signature proves who asks; beforeSettle() then sends nothing to the
 * facilitator and answers a receipt that says nothing was charged.
 *
 * Known limit: that proof is a real EIP-3009 authorization for the full price, valid
 * and unsettled. It is never sent anywhere, but it stays spendable (to our payTo) until it expires, and the
 * buyer's budget counts it until then. A stronger, cheaper proof would be an EIP-191 signature over the jobId,
 * which this leaves out.
 *
 * One instance per request: its state is this request's call, cleared by reset() (JobDesk::afterResponse()
 * calls it; a long-running worker that skips afterResponse() must call it itself).
 */
final class JobDelivery
{
    /** @var ?array{name: string, jobId: ?string, from: string, payment: ?array, reread: bool} this request's `_result`, from payer() */
    private ?array $call = null;
    /** The job this request moved to delivering, until its settle was sent. */
    private ?string $delivering = null;

    /**
     * @param array<string, array{role: string, pairedWith: string, price?: ?string}> $roles     the job tools by name (role: start or result)
     * @param ?\Closure                                                               $sensitive fn(array $params): bool, whether a call's answer must not be kept (SensitiveRules)
     * @param iterable<Scheme>                                                        $schemes   the seller's own schemes, for Settlement::key
     */
    public function __construct(
        private readonly JobStore $jobs,
        private readonly array $roles,
        private readonly JobReconciler $reconciler,
        private readonly JobJanitor $janitor,
        private readonly ?\Closure $sensitive = null,
        private readonly ClockInterface|\Closure|null $clock = null,
        private readonly iterable $schemes = [],
    ) {
    }

    /**
     * Why $from cannot buy this `_result` now, or NULL. Never throws (it runs inside Sale::admit): a store
     * that cannot answer refuses, nothing is charged.
     */
    public function payer(array $params, string $from): ?string
    {
        $args = $params['arguments'] ?? [];
        $payment = $params['_meta'][X402McpMiddleware::PAYMENT] ?? null;
        $jobId = \is_string($args['jobId'] ?? null) ? $args['jobId'] : null;
        $this->call = ['name' => $params['name'], 'jobId' => $jobId, 'from' => $from, 'payment' => \is_array($payment) ? $payment : null, 'reread' => false];
        try {
            $job = null === $jobId ? null : $this->jobs->get($jobId);
            // Another payer's job, or one of another tool, is answered exactly like no job: nothing to learn here.
            if (null === $job || !$job->paidBy($from) || $job->tool !== ($this->roles[$params['name']]['pairedWith'] ?? null)) {
                return 'unknown_job';
            }
            $job = $this->unstick($job);
            if ($job->rereadable($this->now())) {
                // Paid already, and this signature proves it is the payer: answered again, nothing is settled.
                $this->call['reread'] = true;

                return null;
            }

            if ($job->expired()) {
                // Nobody bought it within Job::READY_TTL: the result was deleted, nothing is sold.
                return 'job_expired';
            }

            return match ($job->state) {
                Job::READY => null,
                // Ours: the deposit is not refunded (decision 1), and the result is not charged for.
                Job::FAILED_OURS => 'job_failed_ours',
                // Another `_result` payment is being settled: this one is refused before it is claimed.
                Job::DELIVERING => 'result_pending',
                // Paid once, and the re-read window is over. A retry of THAT payment is replayed by the ledger before
                // this hook runs (unless sensitive: then nothing was kept to replay).
                Job::DELIVERED => 'already_delivered',
                default => 'job_not_ready',
            };
        } catch (\Throwable) {
            return 'jobs_unavailable';
        }
    }

    /**
     * For the `_result` tool: ready > delivering, under the store's lock, for the payer this call proved, with
     * this payment's reference on the job. A re-read gets the delivered job as it is.
     *
     * @throws IllegalTransition     when the job is not ready any more (another payment got it first)
     * @throws \OutOfBoundsException when there is no such job
     */
    public function deliver(string $jobId): Job
    {
        $call = $this->call;
        if (null === $call || $call['jobId'] !== $jobId || null === $call['payment']) {
            throw new IllegalTransition("This call did not pay for job $jobId.");
        }
        if ($call['reread']) {
            $job = $this->jobs->get($jobId);

            // Read again: the window may have closed since payer(); then nothing is delivered, nothing settled.
            return null !== $job && $job->paidBy($call['from']) && $job->rereadable($this->now()) ? $job : throw new IllegalTransition("Job $jobId cannot be read again.");
        }
        $ref = JobPayments::ref($call['payment'], $this->schemes);
        $job = $this->jobs->update($jobId, fn (Job $j) => $j->paidBy($call['from']) && !$j->expired() ? $j->to(Job::DELIVERING, $this->now(), resultRef: $ref) : throw new IllegalTransition("Job $jobId has another payer, or no result left."));
        $this->delivering = $jobId;

        return $job;
    }

    /**
     * Before the facilitator. A re-read answers its own receipt (nothing goes to the facilitator); otherwise
     * NULL, and from here the money may move: whatever happens, the job is never put back to ready blindly.
     */
    public function beforeSettle(array $req): ?array
    {
        if (true === ($this->call['reread'] ?? false)) {
            // The free re-read: the tool answered a delivered job again. The authorization stays unused (the
            // buyer's wallet frees it when it expires), and the receipt says nothing was charged.
            return ['success' => true, 'transaction' => '', 'network' => $req['network'], 'payer' => $this->call['from'], 'extensions' => [JobDesk::META => ['reread' => true, 'charged' => false]]];
        }
        $this->delivering = null;

        return null;
    }

    /**
     * After the facilitator answered. Never throws: Settlement::run would turn the throw into a failed
     * receipt, and withhold an answer that was paid for.
     *
     * Paid: delivered (the result stays Job::RESULT_TTL for a re-read). Refused: ready again, the buyer may pay
     * once more. Unknown (pending, a silent facilitator): left delivering, so no second payment is taken; the
     * job carries this payment's reference, and JobReconciler (or the buyer's next call) looks it up.
     *
     * @param bool $moved   the facilitator settled it
     * @param bool $nothing the facilitator clearly refused: no money moved
     */
    public function afterSettle(bool $moved, bool $nothing): void
    {
        $jobId = $this->call['jobId'] ?? null;
        if (null === $jobId || !($moved || $nothing)) {
            return;
        }
        try {
            $this->jobs->update($jobId, fn (Job $j) => Job::DELIVERING === $j->state ? $j->to($moved ? Job::DELIVERED : Job::READY, $this->now()) : $j);
        } catch (\Throwable) {
            // ponytail: the receipt stands (money may have moved); the row is left for the reconciler.
        }
    }

    /**
     * kernel.terminate. A job handed over but never settled (the middleware withheld the answer: its ledger
     * refused the write, a late screening verdict): nothing was charged, so the buyer may pay again.
     */
    public function afterResponse(): void
    {
        if (null !== $this->delivering) {
            $this->jobs->update($this->delivering, fn (Job $j) => Job::DELIVERING === $j->state ? $j->to(Job::READY, $this->now()) : $j);
        }
    }

    /**
     * Whether the ledger must not keep this `_result`'s answer: the analysis quotes the buyer's text, so it is
     * kept only when neither this call nor the deposit said no. A store that cannot answer counts as sensitive.
     */
    public function sensitive(array $params): bool
    {
        $args = $params['arguments'] ?? [];
        try {
            return X402Middleware::sensitive($this->sensitive, ['name' => $params['name'], 'arguments' => $args]) || false !== $this->jobs->get((string) ($args['jobId'] ?? ''))?->sensitive;
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * For the `<tool>_status` tool: where the job is, for its payer, after the same look payer() takes, so a
     * buyer polling a job nobody will move gets an answer that ends (failed_ours, ready, delivered).
     *
     * @return array{jobId: string, state: string, reason: ?string, retryable: bool, retryAfter: ?int}
     */
    public function status(string $jobId, string $payer): array
    {
        $job = $this->jobs->get($jobId);
        if (null !== $job && $job->paidBy($payer)) {
            try {
                $job = $this->unstick($job);
            } catch (\Throwable) {
                // ponytail: the job as read; the cron moves it.
            }
        }

        return JobStatus::of($jobId, $job, $payer);
    }

    public function reset(): void
    {
        $this->call = null;
        $this->delivering = null;
    }

    /**
     * A job nobody will move: stale work is given up on (JobJanitor::one), a `delivering` one asks the ledger
     * how its payment ended (JobReconciler::delivering; no RPC call from a request, the cron asks the chain).
     */
    private function unstick(Job $job): Job
    {
        return match ($job->state) {
            Job::QUEUED, Job::RUNNING => $this->janitor->one($job, $this->now()),
            Job::DELIVERING => $this->reconciler->delivering($job, $this->now(), false),
            default => $job,
        };
    }

    private function now(): int
    {
        return Clock::now($this->clock);
    }
}
