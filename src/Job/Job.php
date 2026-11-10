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
    public const string SETTLING = 'settling';
    public const string QUEUED = 'queued';
    public const string RUNNING = 'running';
    public const string READY = 'ready';
    public const string DELIVERING = 'delivering';
    public const string DELIVERED = 'delivered';
    public const string FAILED_OURS = 'failed_ours';

    /**
     * Seconds a delivered result stays on the job for its payer to read again for free (the answer of a paid call may be lost on the way back): enough
     * for a client whose answer was lost to retry, short enough that buyer-derived text does not linger.
     */
    public const int RESULT_TTL = 300;

    /**
     * Seconds a `settling` row keeps the buyer's input while nobody can tell whether its deposit moved. A day,
     * as long as the payment reconciler keeps an unresolved payment open before it asks for a person
     * (Quebracho\X402\Reconciler::REVIEW_AFTER): after that the text goes, and a deposit found settled later fails the
     * job as ours (the result is free) instead of running on nothing.
     */
    public const int PARAMS_TTL = 86400;

    /**
     * Seconds a `ready` result waits for its `_result` payment. The result quotes the buyer's text, so a job
     * that is never bought must not keep it forever: after a day the janitor drops it (JobJanitor::forget), the
     * job answers `expired` and `_result` refuses it without charging. Like every TTL here, it needs the cron.
     */
    public const int READY_TTL = 86400;

    /**
     * From each state, where it may go. delivered and failed_ours are final. There is no way back to
     * settling and no way out of it but forward: whether the deposit moved is the reconciler's call.
     */
    public const array TRANSITIONS = [
        self::SETTLING => [self::QUEUED],
        self::QUEUED => [self::RUNNING, self::FAILED_OURS],
        self::RUNNING => [self::READY, self::FAILED_OURS],
        self::READY => [self::DELIVERING],
        self::DELIVERING => [self::DELIVERED, self::READY],
        self::DELIVERED => [],
        self::FAILED_OURS => [],
    ];

    /**
     * The failures that are ours, and the only reasons failed_ours takes: a closed list, so
     * a buyer's mistake can never be filed as ours (and get a free `_result`) by a typo in a reason.
     * The buyer's own mistakes (a bad text) are refused before the deposit is read, and never get a job.
     */
    public const array FAILURES = [
        'provider_error', // what the work depends on (a model's host, an API) failed or refused us
        'invalid_reply',  // it answered, but not what we can deliver
        'our_timeout',    // the work outlived its slot (the janitor found it stale)
        'our_daily_cap',  // our own daily budget ran out after the deposit was taken
    ];

    /**
     * @param string                                                                      $payer         who paid the deposit (authorization.from); `_result` requires the same payer
     * @param string                                                                      $settlementKey Settlement::key of the deposit: `_start` with the same payment gets this job back
     * @param string                                                                      $tool          the `_start` tool that sold it: which work to run
     * @param ?array                                                                      $params        that tool's arguments, kept only until the work has run (buyer text: off disk after)
     * @param ?array                                                                      $result        the work's output, from ready until it is delivered
     * @param ?string                                                                     $reason        why it failed (failed_ours), one of FAILURES
     * @param bool                                                                        $sensitive     the buyer asked that its answer not be kept: `_result`'s answer stays out of the
     *                                                                                                   payment ledger too (kept here, as the input that said so is gone by then)
     * @param ?array{key: string, network?: ?string, nonce?: ?string, validBefore?: ?int} $depositRef    what the reconciler needs to ask the chain about the deposit: network, nonce,
     *                                                                                                   validBefore (Quebracho\X402\Job\JobPayments::ref), never the signature
     * @param ?array{key: string, network?: ?string, nonce?: ?string, validBefore?: ?int} $resultRef     the same for the `_result` payment being settled, plus its Settlement::key:
     *                                                                                                   set on ready > delivering, kept once delivered, gone when the job is ready again
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
    ) {
        if (!isset(self::TRANSITIONS[$state])) {
            throw new \InvalidArgumentException("Unknown job state $state.");
        }
    }

    /**
     * A new job, before its deposit is settled. The id is 128 random bits: it is what the buyer holds
     * to ask for the job, so it must not be guessable.
     *
     * @param ?array{key: string, network?: ?string, nonce?: ?string, validBefore?: ?int} $depositRef Quebracho\X402\Job\JobPayments::ref of the deposit
     */
    public static function settling(string $payer, string $settlementKey, string $tool, array $params, int $now, bool $sensitive = false, ?array $depositRef = null): self
    {
        return new self(bin2hex(random_bytes(16)), self::normalize($payer), $settlementKey, $tool, self::SETTLING, $now, $now, $params, sensitive: $sensitive, depositRef: $depositRef);
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
        $result ??= $this->result;
        if (self::READY === $state && null === $result) {
            throw new IllegalTransition("Job {$this->id}: ready needs a result.");
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

        return new self($this->id, $this->payer, $this->settlementKey, $this->tool, $state, $this->createdAt, $now, $params, $result, self::FAILED_OURS === $state ? $reason : null, $this->sensitive, $this->depositRef, $resultRef);
    }

    /**
     * The same job without the buyer's input or the result: what the janitor leaves once their TTL is over
     * (PARAMS_TTL on an unresolved `settling` row, READY_TTL on a ready one, RESULT_TTL on a delivered one).
     * Not a transition: the
     * state and its time stay, so nothing else (the janitor's staleness, the TTLs) is reset by it.
     */
    public function forget(): self
    {
        return new self($this->id, $this->payer, $this->settlementKey, $this->tool, $this->state, $this->createdAt, $this->updatedAt, null, null, $this->reason, $this->sensitive, $this->depositRef, $this->resultRef);
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
        return new self($row['id'], $row['payer'], $row['settlementKey'], $row['tool'], $row['state'], $row['createdAt'], $row['updatedAt'], $row['params'] ?? null, $row['result'] ?? null, $row['reason'] ?? null, $row['sensitive'] ?? false, $row['depositRef'] ?? null, $row['resultRef'] ?? null);
    }

    private static function normalize(string $payer): string
    {
        return preg_match('/^0x[0-9a-fA-F]{40}$/', $payer) ? strtolower($payer) : $payer;
    }
}
