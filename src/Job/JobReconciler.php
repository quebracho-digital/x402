<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

use Quebracho\X402\PaymentLedger;
use Quebracho\X402\Reconciler;
use Quebracho\X402\Sale;

/**
 * The jobs a lost answer left waiting on a payment, from the janitor's cron (or by hand). It never charges nor runs anything; it moves a job on once JobPayments knows how
 * its payment ended, and otherwise leaves it and says so.
 *
 *   settling    the deposit's settle went out and its request died (a SIGKILL, an OOM) before the job
 *               was queued. Settled: queued (the janitor's run picks it up). Released: the row is
 *               discarded, with the buyer's text. Unknown: left, reported.
 *   delivering  a `_result` settle ended ambiguous (pending, facilitator silent, a reply we could not read).
 *               Settled: delivered, the result kept for its payer's free re-read. Released: ready again,
 *               the buyer may pay once more. Unknown: left (a second payment is still refused).
 *
 * Run it after Reconciler: that one turns the ledger's open 'settling'/'pending' states into
 * settled or released, which is what this one then reads.
 */
final class JobReconciler
{
    /**
     * A `settling` row younger than this belongs to a request that is still running (the settle takes
     * seconds): it is not looked at.
     */
    public const MIN_AGE = 60;

    /**
     * @param ?JobHolds $holds asked first about a hold job's deposit: the escrow knows for sure whether it
     *                         holds the money (JobHolds::deposit), where the token's nonce only says "used"
     */
    public function __construct(
        private readonly JobStore $jobs,
        private readonly JobPayments $payments,
        private readonly PaymentLedger $ledger,
        private readonly ?JobHolds $holds = null,
    ) {
    }

    /**
     * @return list<array{job: string, was: string, result: string}> one row per job looked at; result is
     *                                                               queued, discarded, delivered, ready or unknown
     */
    public function reconcile(int $now): array
    {
        $out = [];
        foreach ($this->jobs->all() as $job) {
            $result = match (true) {
                Job::SETTLING === $job->state && $job->updatedAt <= $now - self::MIN_AGE => $this->settling($job, $now),
                Job::DELIVERING === $job->state => $this->state($this->delivering($job, $now, true), Job::DELIVERING),
                default => null,
            };
            if (null !== $result) {
                $out[] = ['job' => $job->id, 'was' => $job->state, 'result' => $result];
            }
        }

        return $out;
    }

    /**
     * A `delivering` job, moved on when its `_result` payment's fate is known. Also called by JobDelivery when the
     * buyer comes back to it (ledger only, $chain false: a request does not wait on an RPC node).
     *
     * @return Job the job as it is now
     */
    public function delivering(Job $job, int $now, bool $chain): Job
    {
        $ref = $job->resultRef;
        if (Job::DELIVERING !== $job->state || !\is_string($ref['key'] ?? null)) {
            return $job;
        }
        $fate = $this->payments->fate($ref, $job->payer, $job->updatedAt, $now, $chain);
        if (JobPayments::UNKNOWN === $fate) {
            return $job;
        }
        try {
            // Under the store's lock, and only for that same payment: a buyer's retry may have moved it meanwhile.
            return $this->jobs->update($job->id, static fn (Job $j) => Job::DELIVERING === $j->state && ($j->resultRef['key'] ?? null) === $ref['key']
                ? $j->to(JobPayments::SETTLED === $fate ? Job::DELIVERED : Job::READY, $now)
                : throw new IllegalTransition('moved meanwhile'));
        } catch (IllegalTransition|\OutOfBoundsException) {
            return $this->jobs->get($job->id) ?? $job;
        }
    }

    private function settling(Job $job, int $now): string
    {
        $ref = $job->depositRef ?? ['key' => $job->settlementKey];
        // The ledger's claim and 'settling' state for the deposit were written before the row, in the same
        // request (its screening verdict may wait seconds in between): a minute earlier, to be safe.
        $held = null === $job->hold || null === $this->holds ? null : $this->holds->deposit($job, $now);
        $fate = match ($held) {
            true => JobPayments::SETTLED,
            false => JobPayments::RELEASED,
            null => $this->payments->fate($ref, $job->payer, $job->createdAt - Reconciler::GRACE, $now, true),
        };
        if (JobPayments::RELEASED === $fate) {
            // Nothing moved: no job (FileJobStore::discard deletes only a settling one, so a race cannot lose a paid job).
            return $this->jobs->discard($job->id) ? 'discarded' : 'unknown';
        }
        if (JobPayments::UNKNOWN === $fate) {
            return 'unknown';
        }
        $this->letTheRetryRun($job, $ref);
        try {
            $queued = $this->jobs->update($job->id, static fn (Job $j) => $j->to(Job::QUEUED, $now));
        } catch (IllegalTransition) {
            // A buyer's retry queued it meanwhile.
            return 'queued';
        }
        if (null === $queued->params) {
            // Paid, but its input outlived Job::PARAMS_TTL and is gone: nothing to run on. Ours: the result is free.
            $this->jobs->update($job->id, static fn (Job $j) => Job::QUEUED === $j->state ? $j->to(Job::FAILED_OURS, $now, reason: 'our_timeout') : $j);
        }

        return 'queued';
    }

    /**
     * The library leaves an upfront payment whose tool started and never finished in 'handling', and a retry of
     * it gets needs_review: the tool may have had effects, so the library never runs it twice. A `_start` tool
     * is safe to run twice (JobStore::create answers the same job for the same payment), so here, and only for a
     * deposit whose job exists, the state is marked as a settled payment whose tool did not run: the buyer's
     * retry of the same deposit then runs the tool and gets its jobId, with no second settle.
     *
     * Only while the deposit's signature (and so that state) is still alive: with the default 60 s offer
     * (Networks::TIMEOUT) and MIN_AGE it hardly ever is, which is why every `_start` refusal after the row
     * exists carries the jobId (JobIdOnRefusal) and the buyer polls `_status` instead of retrying.
     *
     * @param array{key: string, validBefore?: ?int} $ref
     */
    private function letTheRetryRun(Job $job, array $ref): void
    {
        try {
            $state = $this->ledger->get($job->settlementKey);
            if ('handling' !== ($state['status'] ?? null) || isset($state['response'])) {
                return;
            }
            $state['status'] = 'settled';
            $state['handler_ran'] = false;
            // The TTL the library gives a settled state: until the signature expires, at most Sale::TTL.
            $this->ledger->put($job->settlementKey, $state, Sale::ttl(['payload' => ['authorization' => ['validBefore' => $ref['validBefore'] ?? null]]], $state));
        } catch (\Throwable) {
            // ponytail: the job is queued all the same; only the buyer's retry keeps answering needs_review.
        }
    }

    private function state(Job $job, string $was): string
    {
        return $job->state === $was ? 'unknown' : $job->state;
    }
}
