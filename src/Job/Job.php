<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

/**
 * One paid job (job mode, docs/job-mode.md): the deposit, the work, and the paid delivery of its result.
 * A long work is sold in two payments: a `_start` tool takes the deposit (upfront) and queues the work, a
 * `_result` tool sells the result once it is ready; a free `_status` says when. Immutable: a transition returns a new Job; JobStore::update writes it.
 *
 * The life of a job, and why each state exists:
 *
 *     settling ─> queued ─> running ─> ready ─> delivering ─> delivered
 *                   │          │                    │
 *                   └──────────┴─> failed_ours      └─> ready (the `_result` payment did not settle)
 *
 * A job paid with an `auth-capture` hold ($hold) is sold in ONE payment: `_start` puts the whole price on
 * hold in the escrow, the work runs, we capture, and `_result` delivers to the payer for free:
 *
 *     settling ─> queued ─> running ─> capturing ─> ready ─> delivered
 *                   │          │           │
 *                   └──────────┴───────────┴─> failed_ours (the hold is voided: the buyer pays nothing)
 *
 * - capturing: the work is done and its result kept; the capture is being sent (JobHolds). Ready only once
 *   the escrow paid us: a result is never handed out on a hold that might still go back to the buyer.
 *
 * - settling: written BEFORE the deposit is settled. A crash between the settle and
 *   the next write leaves this row, keyed by the deposit's Settlement::key, for the reconciler to find.
 *   Nothing runs from here: only a confirmed settle moves it on.
 * - queued: the deposit settled. The tool name and its input are kept, so the work can be resumed.
 * - delivering: a `_result` payment is being settled. Its Settlement::key is on the job ($resultRef), so
 *   whoever finds the job here (the next `_result`, the reconciler) can ask the ledger or the chain how it
 *   ended, instead of leaving the job stuck when the settle's answer was lost.
 * - delivered: paid. The result stays RESULT_TTL seconds more, for a free re-read by its payer (the
 *   answer of a paid call may be lost on the way back), then the janitor drops it.
 * - failed_ours: our failure by the fixed list below; `_result` costs nothing.
 */
final class Job
{
    public const SETTLING = 'settling';
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const READY = 'ready';
    public const DELIVERING = 'delivering';
    public const DELIVERED = 'delivered';
    public const FAILED_OURS = 'failed_ours';
    public const CAPTURING = 'capturing';

    /**
     * Seconds a delivered result stays on the job for its payer to read again for free (the answer of a paid call may be lost on the way back): enough
     * for a client whose answer was lost to retry, short enough that buyer-derived text does not linger.
     */
    public const RESULT_TTL = 300;

    /**
     * Seconds a `settling` row keeps the buyer's input while nobody can tell whether its deposit moved. A day,
     * as long as the payment reconciler keeps an unresolved payment open before it asks for a person
     * (Quebracho\X402\Reconciler::REVIEW_AFTER): after that the text goes, and a deposit found settled later fails the
     * job as ours (the result is free) instead of running on nothing.
     */
    public const PARAMS_TTL = 86400;

    /**
     * Seconds a `ready` result waits for its `_result` payment. The result quotes the buyer's text, so a job
     * that is never bought must not keep it forever: after a day the janitor drops it (JobJanitor::forget), the
     * job answers `expired` and `_result` refuses it without charging. Like every TTL here, it needs the cron.
     */
    public const READY_TTL = 86400;

    /**
     * From each state, where it may go. delivered and failed_ours are final. There is no way back to
     * settling and no way out of it but forward: whether the deposit moved is the reconciler's call.
     */
    public const TRANSITIONS = [
        self::SETTLING => [self::QUEUED],
        self::QUEUED => [self::RUNNING, self::FAILED_OURS],
        self::RUNNING => [self::READY, self::FAILED_OURS, self::CAPTURING],
        self::CAPTURING => [self::READY, self::FAILED_OURS],
        self::READY => [self::DELIVERING, self::DELIVERED],
        self::DELIVERING => [self::DELIVERED, self::READY],
        self::DELIVERED => [],
        self::FAILED_OURS => [],
    ];

    /**
     * The failures that are ours, and the only reasons failed_ours takes: a closed list, so
     * a buyer's mistake can never be filed as ours (and get a free `_result`) by a typo in a reason.
     * The buyer's own mistakes (a bad text) are refused before the deposit is read, and never get a job.
     */
    public const FAILURES = [
        'provider_error', // what the work depends on (a model's host, an API) failed or refused us
        'invalid_reply',  // it answered, but not what we can deliver
        'our_timeout',    // the work outlived its slot (the janitor found it stale)
        'our_daily_cap',  // our own daily budget ran out after the deposit was taken
        'capture_missed', // a hold job: the hold went back to the buyer (voided, or reclaimed after its deadline) before we captured it
    ];

    /**
     * @param string                                                                                                     $payer         who paid the deposit (authorization.from); `_result` requires the same payer
     * @param string                                                                                                     $settlementKey Settlement::key of the deposit: `_start` with the same payment gets this job back
     * @param string                                                                                                     $tool          the `_start` tool that sold it: which work to run
     * @param ?array                                                                                                     $params        that tool's arguments, kept only until the work has run (buyer text: off disk after)
     * @param ?array                                                                                                     $result        the work's output, from ready until it is delivered
     * @param ?string                                                                                                    $reason        why it failed (failed_ours), one of FAILURES
     * @param bool                                                                                                       $sensitive     the buyer asked that its answer not be kept: `_result`'s answer stays out of the
     *                                                                                                                                  payment ledger too (kept here, as the input that said so is gone by then)
     * @param ?array{key: string, network?: ?string, nonce?: ?string, validBefore?: ?int}                                $depositRef    what the reconciler needs to ask the chain about the deposit: network, nonce,
     *                                                                                                                                  validBefore (Quebracho\X402\Job\JobPayments::ref), never the signature
     * @param ?array{key: string, network?: ?string, nonce?: ?string, validBefore?: ?int}                                $resultRef     the same for the `_result` payment being settled, plus its Settlement::key:
     *                                                                                                                                  set on ready > delivering, kept once delivered, gone when the job is ready again
     * @param ?array{network: string, info: array<string, string|int>, end: ?string, transaction: ?string, sentAt: ?int} $hold
     *                                                                                                                                  an `auth-capture` job (JobHolds::of): the escrow's PaymentInfo, how the hold ended
     *                                                                                                                                  (captured, released) and the last capture or void sent; NULL for a two-payment job
     */
    public function __construct(
        public readonly string $id,
        public readonly string $payer,
        public readonly string $settlementKey,
        public readonly string $tool,
        public readonly string $state,
        public readonly int $createdAt,
        public readonly int $updatedAt,
        public readonly ?array $params = null,
        public readonly ?array $result = null,
        public readonly ?string $reason = null,
        public readonly bool $sensitive = false,
        public readonly ?array $depositRef = null,
        public readonly ?array $resultRef = null,
        public readonly ?array $hold = null,
    ) {
        if (!isset(self::TRANSITIONS[$state])) {
            throw new \InvalidArgumentException("Unknown job state $state.");
        }
    }

    /**
     * A new job, before its deposit is settled. The id is 128 random bits: it is what the buyer holds
     * to ask for the job, so it must not be guessable.
     *
     * @param ?array{key: string, network?: ?string, nonce?: ?string, validBefore?: ?int}                                $depositRef Quebracho\X402\Job\JobPayments::ref of the deposit
     * @param ?array{network: string, info: array<string, string|int>, end: ?string, transaction: ?string, sentAt: ?int} $hold       JobHolds::of the deposit, when it is a hold
     */
    public static function settling(string $payer, string $settlementKey, string $tool, array $params, int $now, bool $sensitive = false, ?array $depositRef = null, ?array $hold = null): self
    {
        return new self(bin2hex(random_bytes(16)), self::normalize($payer), $settlementKey, $tool, self::SETTLING, $now, $now, $params, sensitive: $sensitive, depositRef: $depositRef, hold: $hold);
    }

    /**
     * Whether $payer is who paid the deposit. EVM addresses are hex: compared without case (a checksummed
     * and a lower-case address are one wallet). Anything else byte for byte (Solana's base58 has case).
     */
    public function paidBy(string $payer): bool
    {
        return $this->payer === self::normalize($payer);
    }

    /**
     * @param ?array{key: string, network?: ?string, nonce?: ?string, validBefore?: ?int} $resultRef going to delivering: the `_result` payment being settled (Quebracho\X402\Job\JobPayments::ref)
     *
     * @throws IllegalTransition when the table does not allow it, or ready comes without a result, or
     *                           failed_ours without a reason from FAILURES
     */
    public function to(string $state, int $now, ?array $result = null, ?string $reason = null, ?array $resultRef = null): self
    {
        if (!\in_array($state, self::TRANSITIONS[$this->state], true)) {
            throw new IllegalTransition("Job {$this->id}: {$this->state} cannot go to $state.");
        }
        // The two ways of paying do not mix: a hold job is paid before it runs (capturing, then a free
        // delivery), a two-payment job when its result is bought (delivering).
        $held = null !== $this->hold;
        if ($held ? (self::RUNNING === $this->state && self::READY === $state) || \in_array(self::DELIVERING, [$state, $this->state], true) : self::CAPTURING === $state || (self::READY === $this->state && self::DELIVERED === $state)) {
            throw new IllegalTransition("Job {$this->id}: {$this->state} cannot go to $state when ".($held ? 'paid with a hold.' : 'paid in two payments.'));
        }
        $result ??= $this->result;
        if (\in_array($state, [self::READY, self::CAPTURING], true) && null === $result) {
            throw new IllegalTransition("Job {$this->id}: $state needs a result.");
        }
        if (self::FAILED_OURS === $state && !\in_array($reason, self::FAILURES, true)) {
            throw new IllegalTransition("Job {$this->id}: failed_ours needs a reason from Job::FAILURES.");
        }
        // The buyer's input is needed only to run (or resume) the work: once it ran, it goes. The result is
        // kept past delivered for the re-read (RESULT_TTL, then JobJanitor::forget), dropped on a failure.
        // Done here, in the one place every transition passes, so no caller can forget it.
        $params = \in_array($state, [self::SETTLING, self::QUEUED, self::RUNNING], true) ? $this->params : null;
        $result = self::FAILED_OURS === $state ? null : $result;
        // Back to ready, the payment that was settling is out of the picture: the next one brings its own.
        $resultRef = match ($state) {
            self::DELIVERING => $resultRef ?? $this->resultRef,
            self::READY => null,
            default => $this->resultRef,
        };

        return new self($this->id, $this->payer, $this->settlementKey, $this->tool, $state, $this->createdAt, $now, $params, $result, self::FAILED_OURS === $state ? $reason : null, $this->sensitive, $this->depositRef, $resultRef, $this->hold);
    }

    /**
     * The same job with its hold's bookkeeping changed (the capture or void sent, how the hold ended). Not a
     * transition: the state and its time stay.
     *
     * @param array{end?: ?string, transaction?: ?string, sentAt?: ?int} $change
     */
    public function withHold(array $change): self
    {
        if (null === $this->hold) {
            throw new IllegalTransition("Job {$this->id} has no hold.");
        }

        return new self($this->id, $this->payer, $this->settlementKey, $this->tool, $this->state, $this->createdAt, $this->updatedAt, $this->params, $this->result, $this->reason, $this->sensitive, $this->depositRef, $this->resultRef, $change + $this->hold);
    }

    /**
     * The same job without the buyer's input or the result: what the janitor leaves once their TTL is over
     * (PARAMS_TTL on an unresolved `settling` row, READY_TTL on a ready one, RESULT_TTL on a delivered one).
     * Not a transition: the
     * state and its time stay, so nothing else (the janitor's staleness, the TTLs) is reset by it.
     */
    public function forget(): self
    {
        return new self($this->id, $this->payer, $this->settlementKey, $this->tool, $this->state, $this->createdAt, $this->updatedAt, null, null, $this->reason, $this->sensitive, $this->depositRef, $this->resultRef, $this->hold);
    }

    /**
     * Whether its payer may read the result again without paying: delivered, and still within RESULT_TTL.
     */
    public function rereadable(int $now): bool
    {
        return self::DELIVERED === $this->state && null !== $this->result && $now < $this->updatedAt + self::RESULT_TTL;
    }

    /**
     * Whether it is ready but its result is gone (READY_TTL): nothing left to sell.
     */
    public function expired(): bool
    {
        return self::READY === $this->state && null === $this->result;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $row): self
    {
        return new self($row['id'], $row['payer'], $row['settlementKey'], $row['tool'], $row['state'], $row['createdAt'], $row['updatedAt'], $row['params'] ?? null, $row['result'] ?? null, $row['reason'] ?? null, $row['sensitive'] ?? false, $row['depositRef'] ?? null, $row['resultRef'] ?? null, $row['hold'] ?? null);
    }

    private static function normalize(string $payer): string
    {
        return preg_match('/^0x[0-9a-fA-F]{40}$/', $payer) ? strtolower($payer) : $payer;
    }
}
