<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * One paid request, the steps both middlewares share: admit (offline verify,
 * screen the payer, online verify, claim), then settle or abandon.
 *
 * @internal
 */
final class Sale
{
    // The names below moved to Settlement, which is public: this class is internal. Kept so nothing breaks.
    public const string UNAVAILABLE = Settlement::UNAVAILABLE;
    public const string UNEXPECTED_VERIFY = Settlement::UNEXPECTED_VERIFY;
    public const int TTL = Settlement::TTL;
    public const int REFERENCED_TTL = Settlement::REFERENCED_TTL;
    public const int OPEN_TTL = Settlement::OPEN_TTL;
    public const int MAX_BODY = Settlement::MAX_BODY;
    public const string RUN = Settlement::RUN;

    private ?AddressCheck $check = null;
    private bool $claimed = false;
    /** The ledger whose run claim this sale holds, until endRun(). */
    private ?PaymentLedger $running = null;
    /**
     * The 'settling' state intend() stored, and where: the settle adds its transaction to it before sending.
     *
     * @var ?array{0: PaymentLedger, 1: array<string, mixed>}
     */
    private ?array $settling = null;
    /** What refused intend()'s write: the reason and error of the outcome that follows (abandon, ran). */
    private ?\Throwable $ledgerError = null;

    public function __construct(
        private readonly array $schemes,
        private readonly \Closure $claim,
        private readonly \Closure $settle,
        private readonly ?EventDispatcherInterface $events,
        private readonly ?\Closure $verify,
        private readonly ?\Closure $release,
        public readonly array $payment,
        public readonly array $req,
        private readonly ?\Closure $payer = null,
        private readonly int $duration = 0,
        private readonly ?OfacSdn $ofac = null,
    ) {
    }

    /**
     * Settles before the handler runs (extra.paymentFlow = upfront), for
     * handlers that are expensive or cannot be undone.
     */
    public function upfront(): bool
    {
        return 'upfront' === ($this->req['extra']['paymentFlow'] ?? null);
    }

    /**
     * Why the payment cannot buy this, or NULL once it is claimed.
     */
    public function admit(): ?string
    {
        $scheme = $this->scheme();
        // Offline first: a signature too short for this tool costs no facilitator round trip.
        $reason = $scheme->verify($this->payment, $this->req) ?? $this->expiresTooSoon($scheme);
        if (null === $reason && null !== $this->payer) {
            // Who pays is proven now; a buyer this call is not for takes no claim.
            $from = $scheme->payer($this->payment);
            $reason = null === $from ? 'invalid_payload' : ($this->payer)($from);
        }
        // Screened once the signature proved who pays, before anything is spent;
        // a slow lookup started here is read before settling.
        $this->check = null === $reason ? AddressCheck::start($this->events, AddressCheck::PAYER, $scheme->payer($this->payment), $this->req, $this->ofac) : null;
        $reason ??= $this->check?->reason();
        $error = null;
        if (null === $reason && null !== $this->verify) {
            // Online (balance, nonce unused): a bad payment runs nothing and takes no claim.
            try {
                $verdict = ($this->verify)($this->payment, $this->req);
                // NULL is good; a non-empty string is the reason. Anything else refuses, so an adapter that forgets NULL cannot approve by accident.
                $reason = null === $verdict || (\is_string($verdict) && '' !== $verdict) ? $verdict : self::UNEXPECTED_VERIFY;
            } catch (FacilitatorException $e) {
                $reason = self::UNAVAILABLE;
                $error = $e;
            } catch (\Throwable $e) {
                $reason = self::UNEXPECTED_VERIFY;
                $error = $e;
            }
        }
        // Again after the online verify, which may have taken seconds of that validity.
        $reason ??= $this->expiresTooSoon($scheme);
        // Spent before it runs: N concurrent copies get one delivery.
        if (null === $reason && !($this->claimed = ($this->claim)($this->key()))) {
            $reason = 'duplicate_settlement';
        }
        if (null !== $reason) {
            $this->outcome('rejected', reason: $reason, error: $error);
        }

        return $reason;
    }

    /**
     * What this payment already bought, read once its signature proves who paid (offline: a forged
     * payment costs nothing). ['state' => ...] to replay, ['refuse' => reason] to answer with, or
     * NULL to admit it as new. ['run' => state] comes with the run claim held: the caller runs the handler and
     * then calls endRun(), on every path.
     *
     * @return ?array{state?: array<string, mixed>, run?: array<string, mixed>, refuse?: string}
     */
    public function stored(PaymentLedger $ledger, ?\Closure $lookup): ?array
    {
        if (null !== $this->scheme()->verify($this->payment, $this->req)) {
            return null;
        }
        $key = $this->key();
        $state = $ledger->get($key);
        $answer = null === $state ? null : $this->classify($ledger, $key, $state, $lookup);
        if (!isset($answer['run'])) {
            return $answer;
        }
        // Charged upfront, never delivered: of N concurrent retries one runs the handler, the rest wait.
        if (!$ledger->claim(self::RUN.$key, Settlement::ttl($this->payment, [], $this->schemes))) {
            return ['refuse' => 'in_progress'];
        }
        $this->running = $ledger;
        try {
            // Read again under the claim: a retry that finished between our read and our claim already ran it.
            $state = $ledger->get($key);
            $answer = null === $state ? null : $this->classify($ledger, $key, $state, $lookup);
        } catch (\Throwable $e) {
            $this->endRun();
            throw $e;
        }
        if (!isset($answer['run'])) {
            $this->endRun();
        }

        return $answer;
    }

    /**
     * Frees the run claim stored() took, once the handler's outcome is written. A release that fails leaves
     * it to its TTL: retries wait (in_progress), none runs twice.
     */
    public function endRun(): void
    {
        if (null === $this->running) {
            return;
        }
        try {
            $this->running->release(self::RUN.$this->key());
        } catch (\Throwable $e) {
            // See the docblock; the answer already given is not changed.
            $this->fault(PaymentFault::RELEASE_RUN, $e);
        }
        $this->running = null;
    }

    /**
     * @return ?array{state?: array<string, mixed>, run?: array<string, mixed>, refuse?: string}
     */
    private function classify(PaymentLedger $ledger, string $key, array $state, ?\Closure $lookup): ?array
    {
        return match ($state['status'] ?? null) {
            'delivered', 'settled' => $this->answer($state),
            'pending' => $this->confirm($ledger, $key, $state, $lookup),
            'settling' => $this->settling($ledger, $key, $state, $lookup),
            // Upfront, the handler started and never closed: it may have had effects, so it never runs again.
            'handling' => isset($state['response']) ? ['state' => $state] : ['refuse' => 'needs_review'],
            'claimed' => ['refuse' => 'in_progress'],
            // Unknown: never admit it again.
            default => ['refuse' => 'already_settled'],
        };
    }

    /**
     * A stored settled state's answer: its response replays; without one, 'run' when the handler never
     * ran (upfront: charged, nothing delivered yet), else refused (the default flow ran it, and kept no copy).
     *
     * @return array{state?: array<string, mixed>, run?: array<string, mixed>, refuse?: string}
     */
    private function answer(array $state): array
    {
        if (isset($state['response'])) {
            return ['state' => $state];
        }

        return false === ($state['handler_ran'] ?? true) ? ['run' => $state] : ['refuse' => 'already_settled'];
    }

    /**
     * Write-ahead: records an intention before the money (or the handler) can move. FALSE when the store
     * refused it, and then the caller must not go on.
     *
     * @param array<string, mixed> $state
     */
    public function intend(?PaymentLedger $ledger, array $state): bool
    {
        $this->intent($state);
        if (null === $ledger) {
            return true;
        }
        try {
            $this->remember($ledger, $state);
            if ('settling' === $state['status']) {
                $this->settling = [$ledger, $state];
            }

            return true;
        } catch (\Throwable $e) {
            $this->ledgerError = $e;
            $this->fault(PaymentFault::INTEND, $e);

            return false;
        }
    }

    /**
     * PaymentFault: a ledger operation that failed while the sale carried on (the middlewares call it for a
     * state they could not record after a settle).
     */
    public function fault(string $op, \Throwable $error): void
    {
        Events::report($this->events, new PaymentFault($op, $this->key(), (string) $this->req['network'], $error));
    }

    /**
     * PaymentIntent, before the write it announces (and so before the settle or the handler): a crash after
     * the send then still leaves a log line. A broken listener must not block the payment, as in outcome().
     *
     * @param array<string, mixed> $state
     */
    private function intent(array $state): void
    {
        try {
            $receipt = is_array($state['receipt'] ?? null) ? $state['receipt'] : [];
            $ref = static fn (mixed $v): ?string => is_string($v) && '' !== $v ? $v : null;
            $this->events?->dispatch(new PaymentIntent(
                $state['status'],
                $this->key(),
                ['network' => $this->req['network'], 'from' => $this->scheme()->payer($this->payment)],
                ['network' => $this->req['network'], 'payTo' => $this->req['payTo'], 'amount' => $this->req['amount'], 'asset' => $this->req['asset']],
                $ref($receipt['paymentId'] ?? null),
                $ref($receipt['transaction'] ?? null),
            ));
        } catch (\Throwable) {
            // ponytail: a broken log listener must not block a sale; the intent is then only in the ledger.
        }
    }

    /**
     * Records this payment's outcome. A settled state becomes 'delivered' when $response is kept.
     *
     * @param array<string, mixed> $state status ('settled' or 'pending'), receipt, and any extra field
     */
    public function remember(PaymentLedger $ledger, array $state, ?ResponseInterface $response = null): void
    {
        $kept = self::capture($response);
        if (null !== $kept) {
            $state['response'] = $kept;
            $state['status'] = 'settled' === $state['status'] ? 'delivered' : $state['status'];
        }
        $key = $this->key();
        $ledger->put($key, $state, Settlement::ttl($this->payment, $state, $this->schemes));
        $this->track($ledger, $key, $state);
    }

    /**
     * Keeps the ledger's open entries in step with the state just written: 'settling' and 'pending' are opened
     * (the money may have moved, a reconciler checks the chain), 'settled' and 'delivered' closed. A release
     * closes the entry too (PaymentLedger::release).
     *
     * An open that fails throws. In intend() that refuses the 'settling' write, so nothing is sent (503
     * ledger_unavailable), as when the state itself cannot be written: the same store refused. After a settle
     * the middlewares' record() drops it, so the payment is never blocked by the index. A close that fails is
     * dropped: the reconciler finds the state settled and closes the entry.
     *
     * @param array<string, mixed> $state
     */
    private function track(PaymentLedger $ledger, string $key, array $state): void
    {
        $status = $state['status'] ?? null;
        if (\in_array($status, ['settling', 'pending'], true)) {
            $ledger->open($key, $this->ref($state), self::OPEN_TTL);
        } elseif (\in_array($status, ['settled', 'delivered'], true)) {
            try {
                $ledger->close($key);
            } catch (\Throwable $e) {
                // See the docblock.
                $this->fault(PaymentFault::CLOSE, $e);
            }
        }
    }

    /**
     * What a reconciler needs to ask the chain about this payment: never the signature nor a body.
     *
     * @param array<string, mixed> $state
     *
     * @return array<string, mixed>
     */
    private function ref(array $state): array
    {
        $auth = is_array($this->payment['payload']['authorization'] ?? null) ? $this->payment['payload']['authorization'] : [];
        $receipt = is_array($state['receipt'] ?? null) ? $state['receipt'] : [];
        $text = static fn (mixed $v): ?string => is_string($v) && '' !== $v ? $v : null;

        return [
            'network' => $this->req['network'],
            'asset' => $this->req['asset'] ?? null,
            'from' => $this->scheme()->payer($this->payment),
            'nonce' => $text($auth['nonce'] ?? null),
            'payTo' => $this->req['payTo'] ?? null,
            'amount' => $this->req['amount'] ?? null,
            'validBefore' => Schemes::find((string) $this->req['network'], $this->schemes, Expires::class)?->expiresAt($this->payment),
            'transaction' => $text($receipt['transaction'] ?? null),
            'paymentId' => $text($receipt['paymentId'] ?? null),
            'status' => $state['status'],
            // Upfront, a confirmed 'settling' or 'pending' has not run its handler yet (see confirm()).
            'upfront' => $this->upfront(),
        ];
    }

    /**
     * Whether this sale still holds its claim (not freed): the money may have moved.
     */
    public function held(): bool
    {
        return $this->claimed;
    }

    /**
     * @deprecated use Settlement::ttl()
     *
     * @param array<string, mixed> $state
     * @param iterable<Scheme>     $schemes
     */
    public static function ttl(array $payment, array $state = [], iterable $schemes = []): int
    {
        return Settlement::ttl($payment, $state, $schemes);
    }

    /**
     * @deprecated use Settlement::restore()
     */
    public static function restore(array $stored, ResponseFactoryInterface $responses, StreamFactoryInterface $streams): ResponseInterface
    {
        return Settlement::restore($stored, $responses, $streams);
    }

    /**
     * This payment's key in the claim and the ledger, by this seller's schemes.
     */
    public function key(): string
    {
        return Settlement::key($this->payment, $this->schemes);
    }

    private function scheme(): Scheme
    {
        return current(array_filter($this->schemes, fn (Scheme $s) => $s->supports($this->req['network'])))
          ?: throw new \LogicException("No scheme verifies {$this->req['network']}.");
    }

    /**
     * A settle that was sent but whose outcome was not stored (a crash between the two). Only a reference the
     * facilitator gave (transaction or paymentId) can be asked about; without one it is unknown until the TTL
     * frees the claim, and then the online verify refuses a spent nonce.
     *
     * @return ?array{state?: array<string, mixed>, run?: array<string, mixed>, refuse?: string}
     */
    private function settling(PaymentLedger $ledger, string $key, array $state, ?\Closure $lookup): ?array
    {
        $receipt = is_array($state['receipt'] ?? null) ? $state['receipt'] : [];
        $refs = array_filter([$receipt['transaction'] ?? null, $receipt['paymentId'] ?? null], static fn (mixed $v) => is_string($v) && '' !== $v);

        return [] === $refs ? ['refuse' => 'settlement_unknown'] : $this->confirm($ledger, $key, $state, $lookup, 'settlement_unknown');
    }

    /**
     * A pending payment: asks the lookup what the chain did. Confirmed, it becomes settled; refuted, it is
     * freed; unknown (or the lookup failed) it stays pending, or stays unknown ($unknown) for a settling state.
     *
     * @return ?array{state?: array<string, mixed>, run?: array<string, mixed>, refuse?: string}
     */
    private function confirm(PaymentLedger $ledger, string $key, array $state, ?\Closure $lookup, string $unknown = 'settlement_pending'): ?array
    {
        try {
            $receipt = null === $lookup ? null : $lookup($state, $this->payment, $this->req);
        } catch (\Throwable $e) {
            // The lookup goes to the facilitator: unreachable means still pending, not refused.
            $receipt = null;
            $this->fault(PaymentFault::LOOKUP, $e);
        }
        if (false === ($receipt['success'] ?? null)) {
            $ledger->release($key);

            return null;
        }
        // A success about another transaction, network or payer confirms nothing: still unknown, claim kept.
        if (true !== ($receipt['success'] ?? null) || !$this->same($state, $receipt)) {
            return ['refuse' => $unknown];
        }
        // Normalized as a settle's receipt: what cannot be encoded is dropped, so the store does not throw.
        $state['receipt'] = Settlement::settled($receipt);
        if ('settling' === ($state['status'] ?? null) && $this->upfront()) {
            // Upfront, a settling state is written before the handler: confirmed, the handler has not run yet.
            $state['handler_ran'] = false;
        }
        $state['status'] = isset($state['response']) ? 'delivered' : 'settled';
        $ledger->put($key, $state, Settlement::ttl($this->payment, $state, $this->schemes));
        $this->track($ledger, $key, $state);

        return $this->answer($state);
    }

    /**
     * Whether a lookup's receipt is about this payment: its network, the stored transaction and paymentId
     * (when stored), and the payer the signature proves (when the lookup names one). Compared as the scheme spells them
     * (AddressForm: EVM hex without case, base58 exactly); byte for byte when the scheme does not say.
     */
    private function same(array $state, array $receipt): bool
    {
        $stored = is_array($state['receipt'] ?? null) ? $state['receipt'] : [];
        $form = Schemes::find((string) $this->req['network'], $this->schemes, AddressForm::class);
        $eq = static fn (mixed $a, mixed $b): bool => is_string($a) && is_string($b) && (null !== $form ? $form->same($a, $b) : $a === $b);
        $known = static fn (mixed $v): bool => is_string($v) && '' !== $v;

        return ($receipt['network'] ?? null) === $this->req['network']
          && (!$known($stored['transaction'] ?? null) || $eq($receipt['transaction'] ?? null, $stored['transaction']))
          && (!$known($stored['paymentId'] ?? null) || !isset($receipt['paymentId']) || $eq($receipt['paymentId'], $stored['paymentId']))
          && (null === ($receipt['payer'] ?? null) || $eq($receipt['payer'], $this->scheme()->payer($this->payment)));
    }

    /**
     * The body is kept as base64 (JSON-safe) and only when it is a 2xx of at most MAX_BODY bytes.
     *
     * @return ?array{status: int, headers: array<string, string>, body: string}
     */
    private static function capture(?ResponseInterface $response): ?array
    {
        if (null === $response || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return null;
        }
        $body = $response->getBody();
        if (!$body->isSeekable() || (null !== $body->getSize() && $body->getSize() > self::MAX_BODY)) {
            return null;
        }
        $body->rewind();
        $text = (string) $body;
        $body->rewind();
        if (strlen($text) > self::MAX_BODY) {
            return null;
        }

        return [
            'status' => $response->getStatusCode(),
            'headers' => array_map(static fn (array $values) => implode(', ', $values), $response->getHeaders()),
            'body' => base64_encode($text),
        ];
    }

    /**
     * The receipt; a failure that moved nothing frees the claim.
     */
    public function settle(?int $handlerStatus = null): array
    {
        $late = $this->check?->verdict();
        $referenced = '';
        // A settle that takes it (ChainFacilitator) adds its transaction to the 'settling' state before sending;
        // a write the store refuses sends nothing.
        $reference = null === $this->settling ? null : function (string $transaction) use (&$referenced): bool {
            [$ledger, $state] = $this->settling;
            if (!$this->intend($ledger, ['receipt' => ['transaction' => $transaction, 'network' => $this->req['network']]] + $state)) {
                return false;
            }
            $referenced = $transaction;

            return true;
        };
        $error = null;
        $receipt = null === $late
          ? Settlement::run($this->settle, $this->payment, $this->req, $reference, $error)
          // Ran but not paid for: withheld, as when settlement fails.
          : ['success' => false, 'errorReason' => $late, 'transaction' => '', 'network' => $this->req['network']];
        if (true !== $receipt['success'] && '' !== $referenced && '' === $receipt['transaction']) {
            // Sent, then the settle lost its hash (a wrapper that threw): the stored one is kept, not overwritten.
            $receipt['transaction'] = $referenced;
        }
        if (true === $receipt['success']) {
            $this->outcome('settled', $handlerStatus, $receipt);
        } else {
            // A transaction may still land (pending, or a facilitator that went
            // silent after we sent it, or one whose success did not add up): keep the claim. Only a clear refusal frees it.
            if ('' === ($receipt['transaction'] ?? '') && !\in_array($receipt['errorReason'], Settlement::MAY_HAVE_MOVED, true)) {
                $this->free();
            }
            $this->outcome('not_settled', $handlerStatus, $receipt, $receipt['errorReason'], $error);
        }

        return $receipt;
    }

    /**
     * The handler delivered nothing, or the ledger refused intend()'s write (reason ledger_unavailable):
     * nothing charged, the payment is good again.
     */
    public function abandon(?int $handlerStatus): void
    {
        $this->free();
        $this->outcome('failed', $handlerStatus, reason: null === $this->ledgerError ? null : 'ledger_unavailable', error: $this->ledgerError);
    }

    /**
     * Upfront: money moved before the handler; record a handler that then failed,
     * so the seller can refund by hand.
     */
    public function ran(int $handlerStatus): void
    {
        if ($handlerStatus < 200 || $handlerStatus >= 300) {
            $this->outcome('failed_after_settle', $handlerStatus, reason: null === $this->ledgerError ? null : 'ledger_unavailable', error: $this->ledgerError);
        }
    }

    /**
     * A slow handler must not outlive the signature: settled after it, an expired
     * authorization would deliver unpaid. Checked right after the offline verify
     * and again last before the claim, once the online verify spent its time.
     * Only a call that declares a duration, and only a scheme whose payments carry an expiry (Expires):
     * any other has none we can read, and is refused.
     */
    private function expiresTooSoon(Scheme $scheme): ?string
    {
        if ($this->duration <= 0) {
            return null;
        }

        return $scheme instanceof Expires ? $scheme->tooShortFor($this->payment, $this->duration) : 'unsupported_scheme';
    }

    private function free(): void
    {
        if ($this->claimed && null !== $this->release) {
            try {
                ($this->release)($this->key());
            } catch (LedgerUnavailableException $e) {
                // ponytail: the claim stays until its TTL, so the buyer waits (409 in_progress) but cannot pay twice; the answer is not changed by a failed release.
                $this->fault(PaymentFault::RELEASE, $e);

                return;
            }
            $this->claimed = false;
        }
    }

    private function outcome(string $status, ?int $handlerStatus = null, ?array $receipt = null, ?string $reason = null, ?\Throwable $error = null): void
    {
        // A broken log listener must not undo a sale (money may have moved): Events drops its errors.
        Events::report($this->events, new PaymentOutcome($status, $this->payment, $this->req, $handlerStatus, $receipt, $reason, $error));
    }
}
