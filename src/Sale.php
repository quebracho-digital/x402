<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * One paid request, the steps both middlewares share: admit (offline verify,
 * screen the payer, online verify, claim), then settle or abandon.
 *
 * @internal
 */
final class Sale
{
    public const UNAVAILABLE = 'facilitator_unavailable';

    private ?AddressCheck $check = null;
    private bool $claimed = false;

    public function __construct(
        private readonly array $schemes,
        private readonly \Closure $claim,
        private readonly ?\Closure $settle,
        private readonly ?EventDispatcherInterface $events,
        private readonly ?\Closure $verify,
        private readonly ?\Closure $release,
        public readonly array $payment,
        public readonly array $req,
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
        $scheme = current(array_filter($this->schemes, fn (Scheme $s) => $s->supports($this->req['network'])))
          ?: throw new \LogicException("No scheme verifies {$this->req['network']}.");
        $reason = $scheme->verify($this->payment, $this->req);
        // Screened once the signature proved who pays, before anything is spent;
        // a slow lookup started here is read before settling.
        $this->check = null === $reason ? AddressCheck::start($this->events, AddressCheck::PAYER, $scheme->payer($this->payment), $this->req) : null;
        $reason ??= $this->check?->reason();
        if (null === $reason && null !== $this->verify) {
            // Online (balance, nonce unused): a bad payment runs nothing and takes no claim.
            try {
                $reason = ($this->verify)($this->payment, $this->req);
            } catch (FacilitatorException) {
                $reason = self::UNAVAILABLE;
            }
        }
        // Spent before it runs: N concurrent copies get one delivery.
        if (null === $reason && !($this->claimed = ($this->claim)(Settlement::key($this->payment)))) {
            $reason = 'duplicate_settlement';
        }
        if (null !== $reason) {
            $this->outcome('rejected', reason: $reason);
        }

        return $reason;
    }

    /**
     * The receipt; a failure that moved nothing frees the claim.
     */
    public function settle(?int $handlerStatus = null): array
    {
        $late = $this->check?->verdict();
        $receipt = null === $late
          ? Settlement::run($this->settle, $this->payment, $this->req)
          // Ran but not paid for: withheld, as when settlement fails.
          : ['success' => false, 'errorReason' => $late, 'transaction' => '', 'network' => $this->req['network']];
        if (true === $receipt['success']) {
            $this->outcome('settled', $handlerStatus, $receipt);
        } else {
            // A transaction may still land (settlement_pending): keep the claim.
            if ('' === ($receipt['transaction'] ?? '') && 'settlement_pending' !== $receipt['errorReason']) {
                $this->free();
            }
            $this->outcome('not_settled', $handlerStatus, $receipt, $receipt['errorReason']);
        }

        return $receipt;
    }

    /**
     * The handler delivered nothing: nothing charged, the payment is good again.
     */
    public function abandon(?int $handlerStatus): void
    {
        $this->free();
        $this->outcome('failed', $handlerStatus);
    }

    private function free(): void
    {
        if ($this->claimed && null !== $this->release) {
            ($this->release)(Settlement::key($this->payment));
            $this->claimed = false;
        }
    }

    private function outcome(string $status, ?int $handlerStatus = null, ?array $receipt = null, ?string $reason = null): void
    {
        try {
            $this->events?->dispatch(new PaymentOutcome($status, $this->payment, $this->req, $handlerStatus, $receipt, $reason));
        } catch (\Throwable) {
            // ponytail: a broken log listener must not undo a sale (money may have moved); errors here are dropped.
        }
    }
}
