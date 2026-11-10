<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

use Psr\Clock\ClockInterface;
use Quebracho\X402\Authorization;
use Quebracho\X402\Clock;
use Quebracho\X402\Scheme;
use Quebracho\X402\Settlement;
use Quebracho\X402\Signer;
use Quebracho\X402\X402McpMiddleware;
use Quebracho\X402\X402Middleware;

/**
 * The deposit: a `_start` call of a job tool, from its first check to the job it queues.
 *
 * The deposit is paid upfront (extra.paymentFlow = upfront): it settles BEFORE the tool runs, and it is not
 * refunded. So everything that could make the sale pointless is asked before the money moves, in this order
 * (JobDesk routes the middleware's hooks here for a `_start`):
 *
 *   check()         X402McpMiddleware's $validate, before any payment is read: the work's own check of the
 *                   input. A text the work would refuse is never paid for
 *   payer()         $payer, once the signature proved who pays: our own known failures (the daily cap). No
 *                   slot here: the online verify and the address screening have not run yet
 *   beforeSettle()  $settle, just before the facilitator: takes a free concurrency slot, then writes the
 *                   `settling` job row. The slot is taken after the online verify and the screening, so a
 *                   signature that fails them never holds one; still before the money moves, so a busy server
 *                   refuses the sale instead of selling work it cannot start. A crash after the settle leaves
 *                   the row, keyed by the deposit's Settlement::key, for JobReconciler; a row that cannot be
 *                   written sends nothing
 *   afterSettle()   the facilitator answered: a clear refusal discards the row (and the buyer's text with it)
 *   queue()         the tool, after the settle: settling > queued, the same job for the same payment (7c)
 *   afterResponse() kernel.terminate: runs the queued job in the slot beforeSettle() took (a retry that never
 *                   went through beforeSettle() takes one here)
 *
 * And whatever a `_start` answers once its job row exists, a refusal included (settlement_pending,
 * needs_review, facilitator_unavailable, an expired signature on a late retry), carries the jobId:
 * jobIdFor(), added by JobIdOnRefusal. The deposit's signature only lives a minute or two and the
 * reconciler looks at a `settling` row only after JobReconciler::MIN_AGE, so a buyer who had to wait for a
 * retry would find its signature expired and never learn the job; with the jobId it polls `_status` instead.
 *
 * One instance per request: its state is this request's call, cleared by reset() (JobDesk::afterResponse()
 * calls it; a long-running worker that skips afterResponse() must call it itself).
 */
final class JobDeposit
{
    /** @var ?array{name: string, args: array, from: string} this request's `_start`, from payer() */
    private ?array $call = null;
    private ?JobSlot $slot = null;
    /** The `settling` row beforeSettle() wrote for this request, until the facilitator answered. */
    private ?Job $settling = null;
    /** The job this request queued: afterResponse() runs it. */
    private ?string $queued = null;

    /**
     * @param array<string, array{role: string, pairedWith: string, price?: ?string}> $roles     the job tools by name (role: start or result)
     * @param \Closure                                                                $price     the middleware's $price: fn(array $params): ?array
     * @param ?\Closure                                                               $sensitive fn(array $params): bool, whether a call's answer must not be kept (SensitiveRules)
     * @param iterable<Scheme>                                                        $schemes   the seller's own schemes, for Settlement::key
     */
    public function __construct(
        private readonly JobStore $jobs,
        private readonly JobRunner $runner,
        private readonly JobSlots $slots,
        private readonly \Closure $price,
        private readonly array $roles,
        private readonly ?\Closure $sensitive = null,
        private readonly ClockInterface|\Closure|null $clock = null,
        private readonly iterable $schemes = [],
    ) {
    }

    /**
     * Why this `_start` input must not be sold, or NULL. Fails closed: a `_start` tool with no work behind it
     * would take a deposit for nothing.
     */
    public function check(array $params): ?string
    {
        $work = $this->runner->work($params['name']);

        return null === $work ? "Tool {$params['name']} has no work to run." : $work->check($params['arguments'] ?? []);
    }

    /**
     * Why $from cannot buy this `_start` now, or NULL. Never throws (it runs inside Sale::admit): a work that
     * cannot answer refuses, nothing is charged.
     */
    public function payer(array $params, string $from): ?string
    {
        $this->call = ['name' => $params['name'], 'args' => $params['arguments'] ?? [], 'from' => $from];
        try {
            // No slot yet: this signature has not passed the online verify (beforeSettle() takes it).
            return $this->runner->work($params['name'])?->refuse();
        } catch (\Throwable) {
            return 'jobs_unavailable';
        }
    }

    /**
     * Before the facilitator: a free slot, then the `settling` row. Answers a receipt instead of the
     * facilitator's when the sale must not go on, or NULL to settle.
     *
     * Every refusal here is a clear one (no transaction, a reason outside Settlement::MAY_HAVE_MOVED): the
     * library frees the claim, and the same payment can be retried.
     */
    public function beforeSettle(array $payment, array $req): ?array
    {
        $refused = static fn (string $reason): array => ['success' => false, 'errorReason' => $reason, 'transaction' => '', 'network' => $req['network']];
        if (null === $this->call) {
            // ponytail: unreachable, the middleware calls $payer before $settle.
            return $refused('jobs_unavailable');
        }
        // Taken now and held to the end of the request: the runner works in it after the response.
        try {
            $this->slot ??= $this->slots->take();
        } catch (\Throwable) {
            // A lock store that cannot answer: the same clear refusal (Settlement::run would turn the throw into
            // unexpected_settle_error, which keeps the claim, and the buyer could not retry for the signature's life).
            return $refused('job_slots_unavailable');
        }
        if (null === $this->slot) {
            return $refused('job_slots_busy');
        }
        try {
            $this->settling = $this->jobs->create($this->settlingJob($this->call['from'], $payment, $this->call['name'], $this->call['args']));
        } catch (\Throwable) {
            // Nothing was sent: refused the same way.
            return $refused('jobs_unavailable');
        }

        return null;
    }

    /**
     * After the facilitator answered. Never throws: the receipt stands whatever happens to the row.
     *
     * @param bool $nothing the facilitator clearly refused: no money moved (JobDesk::settle)
     */
    public function afterSettle(bool $nothing): void
    {
        try {
            if (null !== $this->settling && $nothing) {
                // The deposit was clearly refused: no job, and the buyer's text off disk.
                $this->jobs->discard($this->settling->id);
            }
        } catch (\Throwable) {
            // ponytail: the receipt stands; the row is left for the reconciler.
        }
        $this->settling = null;
    }

    /**
     * For the `_start` tool, once the deposit settled (it runs only then): the job, queued. The same payment
     * always gets the same job (decision 7c), whatever state it reached.
     *
     * @param ?array $payment params._meta["x402/payment"] of this call
     *
     * @throws \RuntimeException when the job cannot be read or written
     */
    public function queue(string $name, array $args, ?array $payment): Job
    {
        if (null === $payment) {
            // ponytail: unreachable, the middleware runs a paid tool only with its payment.
            throw new \LogicException("$name ran without a payment.");
        }
        $job = $this->jobs->bySettlement(Settlement::key($payment, $this->schemes));
        if (null === $job) {
            // Settled, but no row was written before: only when the settle did not go through beforeSettle() (a
            // wiring mistake). The money moved, so the job is written now rather than refused.
            $from = $this->call['from'] ?? $payment['payload']['authorization']['from'] ?? throw new \LogicException('No payer for the deposit.');
            $job = $this->jobs->create($this->settlingJob($from, $payment, $name, $args));
        }
        if (Job::SETTLING === $job->state) {
            $job = $this->jobs->update($job->id, fn (Job $j) => Job::SETTLING === $j->state ? $j->to(Job::QUEUED, $this->now()) : $j);
        }
        if (Job::QUEUED === $job->state) {
            $this->queued = $job->id;
        }

        return $job;
    }

    /**
     * kernel.terminate, after the response went out: the job this request queued, run in this request's slot.
     */
    public function afterResponse(): void
    {
        if (null === $this->queued) {
            return;
        }
        // A retry that queued the job never went through beforeSettle(), so it holds no slot yet. None free: the
        // job stays queued, and the janitor's cron run starts it once a slot is free (JobJanitor::orphans), or
        // gives up on it (failed_ours) if it waited too long, rather than overload the model.
        try {
            $this->slot ??= $this->slots->take();
        } catch (\Throwable) {
            // ponytail: a lock store that cannot answer: the job stays queued, for the cron.
        }
        if (null !== $this->slot) {
            $this->runner->run($this->queued);
        }
    }

    /**
     * The jobId of the deposit in these tools/call params, for JobIdOnRefusal to add to a `_start`
     * refusal, or NULL. Only for the payer: the deposit's EIP-3009 signature must recover to its `from`, checked
     * against our own offer (not the buyer's copy). Its validity window is NOT checked, on purpose: a late
     * retry with an expired signature is exactly the buyer who lost the jobId. A signature, expired or not,
     * proves the key; the job row is found by the deposit's Settlement::key and must be that payer's.
     *
     * ponytail: a lookup by the store's index, not a deterministic id (an HMAC over payer, network and nonce):
     * the index already maps the deposit to its job, and an HMAC would need a secret. EVM only, like the
     * deposit itself.
     */
    public function jobIdFor(array $params): ?string
    {
        $name = (string) ($params['name'] ?? '');
        if ('start' !== ($this->roles[$name]['role'] ?? null)) {
            return null;
        }
        $meta = $params['_meta'] ?? null;
        $payment = \is_array($meta) ? ($meta[X402McpMiddleware::PAYMENT] ?? null) : null;
        $payload = \is_array($payment) ? ($payment['payload'] ?? null) : null;
        $auth = \is_array($payload) ? ($payload['authorization'] ?? null) : null;
        $signature = \is_array($payload) ? ($payload['signature'] ?? null) : null;
        if (!\is_array($payment) || !\is_array($auth) || !\is_string($signature) || !\is_string($auth['from'] ?? null)) {
            return null;
        }
        try {
            $accepted = $payment['accepted'] ?? null;
            $network = \is_array($accepted) ? ($accepted['network'] ?? null) : null;
            $priced = ['name' => $name, 'arguments' => \is_array($params['arguments'] ?? null) ? $params['arguments'] : []];
            $req = current(array_filter(($this->price)($priced) ?? [], static fn (array $a) => $a['network'] === $network));
            if (false === $req || !\is_string($req['network'] ?? null) || !str_starts_with($req['network'], 'eip155:')
                || 0 !== strcasecmp((string) Signer::recover(Authorization::digest($req, $auth), $signature), $auth['from'])) {
                return null;
            }
            $job = $this->jobs->bySettlement(Settlement::key($payment, $this->schemes));
        } catch (\Throwable) {
            // A malformed authorization (the encoder throws) or a store that cannot answer: no jobId, the refusal as it was.
            return null;
        }

        return null !== $job && $job->paidBy($auth['from']) && $job->tool === $name ? $job->id : null;
    }

    public function reset(): void
    {
        $this->slot?->release();
        $this->slot = null;
        $this->call = null;
        $this->settling = null;
        $this->queued = null;
    }

    /** The row of a deposit about to settle (or settled without one): keyed by its Settlement::key. */
    private function settlingJob(string $from, array $payment, string $name, array $args): Job
    {
        return Job::settling($from, Settlement::key($payment, $this->schemes), $name, $args, $this->now(), X402Middleware::sensitive($this->sensitive, ['name' => $name, 'arguments' => $args]), JobPayments::ref($payment, $this->schemes));
    }

    private function now(): int
    {
        return Clock::now($this->clock);
    }
}
