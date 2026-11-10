<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * A spending cap per network, in the token's atomic units.
 *
 * ponytail: in memory, so it lasts one process (one agent run); persist the
 * spent totals if a cap must hold across runs.
 *
 * Reserved before signing (reserve(), check and count in one step, so
 * concurrent payments in this process share the cap) and given back only if
 * the payment never left (release()); never refunded on a seller's word. With an on-chain
 * check ($used), a signature the seller never settled frees its amount once
 * it has expired and the chain says its nonce is unused: then it can never
 * move money.
 */
final class Budget
{
    /**
     * The longest a signed payment may stay valid, whatever the seller asks.
     */
    public const int MAX_TIMEOUT = 600;

    /**
     * Atomic units spent so far, by network.
     */
    private array $spent = [];

    /**
     * Signed but not confirmed settled: [network, amount, from, nonce, validBefore].
     */
    private array $unsettled = [];

    /**
     * @param array<string, string>        $caps
     *                                                 Network => maximum atomic units, e.g. ['eip155:84532' => '50000'] for
     *                                                 0.05 USDC. Networks without a cap get nothing.
     * @param ?\Closure                    $used
     *                                                 fn(string $network, string $from, string $nonce): bool — the token's
     *                                                 EIP-3009 authorizationState on chain. NULL never frees anything.
     * @param ClockInterface|\Closure|null $clock
     *                                                 Returns the current Unix time; tests pin it
     * @param int                          $maxTimeout
     *                                                 The longest validity this buyer signs (at most MAX_TIMEOUT): no more
     *                                                 than its HTTP client waits for the answer, or it can pay for one it
     *                                                 never reads
     */
    public function __construct(
        private readonly array $caps,
        private readonly ?\Closure $used = null,
        private readonly ClockInterface|\Closure|null $clock = null,
        private readonly array $perPayment = [],
        private readonly int $maxTimeout = self::MAX_TIMEOUT,
    ) {
    }

    /**
     * Caps in dollars, one per network, and optionally a ceiling per single
     * payment: ['eip155:84532' => '0.05'], ['eip155:84532' => '0.02'].
     * A network with no cap here gets nothing.
     *
     * @param array<string, string> $usd
     * @param array<string, string> $perPaymentUsd
     */
    public static function fromUsd(array $usd, array $perPaymentUsd = [], ?\Closure $used = null, int $maxTimeout = self::MAX_TIMEOUT): self
    {
        $atomic = static fn (array $m): array => array_map(static fn (string $v, string $n) => Networks::atomic($v, Networks::get($n)['decimals']), $m, array_keys($m));

        return new self(array_combine(array_keys($usd), $atomic($usd)), $used, null, [] === $perPaymentUsd ? [] : array_combine(array_keys($perPaymentUsd), $atomic($perPaymentUsd)), $maxTimeout);
    }

    /**
     * A cap in dollars on one network, converted with the token's decimals.
     */
    public static function usd(string $network, string $usd, ?\Closure $used = null): self
    {
        return new self([$network => Networks::atomic($usd, Networks::get($network)['decimals'])], $used);
    }

    /**
     * Whether to sign this offer at all. The seller wrote it, so nothing in
     * it is trusted: only known tokens, a sane amount and a short validity, and what the network's scheme
     * says it may sign (VetsOffers; a scheme that does not vet offers buys nothing).
     *
     * @param iterable<Scheme> $schemes the buyer's own schemes, asked before the library's
     */
    public function allows(array $req, iterable $schemes = []): bool
    {
        $network = $req['network'] ?? null;
        $cap = is_string($network) ? $this->caps[$network] ?? null : null;
        if (null === $cap || !is_string($req['amount'] ?? null) || !preg_match('/^[1-9][0-9]{0,77}$/', $req['amount'])) {
            return false;
        }
        $timeout = $req['maxTimeoutSeconds'] ?? null;
        if (!is_int($timeout) || $timeout < 1 || $timeout > min($this->maxTimeout, self::MAX_TIMEOUT)) {
            return false;
        }
        $token = Networks::find($network);
        $form = Schemes::find($network, $schemes, AddressForm::class);
        // The cap is in this token's units: any other asset would make it meaningless.
        $asset = (string) ($req['asset'] ?? '');
        if (null === $token || !(null !== $form ? $form->same($asset, (string) $token['asset']) : $asset === $token['asset'])) {
            return false;
        }
        // What only the scheme can read: payTo in its one spelling, the token's own domain, the fee payer.
        if (true !== Schemes::find($network, $schemes, VetsOffers::class)?->signable($req, $token)) {
            return false;
        }

        if (isset($this->perPayment[$network]) && gmp_cmp($req['amount'], $this->perPayment[$network]) > 0) {
            return false;
        }

        return gmp_cmp(gmp_add($this->spent[$network] ?? 0, $req['amount']), $cap) <= 0;
    }

    /**
     * The scheme to pay $req with, or why not: no_scheme, not_allowed
     * (over budget, unknown token, odd offer) or the payTo screening's reason.
     *
     * @param Scheme[] $schemes
     *
     * @return array{?Scheme, ?string}
     */
    public function choose(array $schemes, mixed $req, ?EventDispatcherInterface $events, ?OfacSdn $ofac = null): array
    {
        $scheme = is_array($req) ? current(array_filter($schemes, fn (Scheme $s) => $s->supports((string) ($req['network'] ?? '')))) : false;
        $reason = match (true) {
            false === $scheme => 'no_scheme',
            !$this->allows($req, $schemes) => 'not_allowed',
            default => AddressCheck::run($events, AddressCheck::PAY_TO, is_string($req['payTo'] ?? null) ? $req['payTo'] : null, $req, $ofac),
        };

        return null === $reason ? [$scheme, null] : [null, $reason];
    }

    /**
     * Checks the offer and counts it as spent in one step, or returns false
     * and changes nothing. Nothing in between can yield (no I/O, no Fiber
     * suspension), so payments running concurrently on this object cannot
     * all pass the check before any of them counts: the cap holds.
     */
    public function reserve(array $req, iterable $schemes = []): bool
    {
        if (!$this->allows($req, $schemes)) {
            return false;
        }
        $this->spend($req);

        return true;
    }

    /**
     * Gives back a reserve() whose payment never left this process (the
     * signature failed, or the request could not be built). Never for one
     * that may have reached the seller: that is unsettled()'s job.
     */
    public function release(array $req): void
    {
        $this->spent[$req['network']] = gmp_strval(gmp_sub($this->spent[$req['network']] ?? 0, (string) $req['amount']));
    }

    /**
     * Counts $req as spent without checking it; reserve() checks and counts.
     */
    public function spend(array $req): void
    {
        $this->spent[$req['network']] = gmp_strval(gmp_add($this->spent[$req['network']] ?? 0, (string) $req['amount']));
    }

    /**
     * Remembers a signed payment whose receipt did not say success, so
     * reclaim() can free it once it can no longer settle.
     */
    public function unsettled(array $req, array $payment): void
    {
        $auth = $payment['payload']['authorization'] ?? null;
        // ponytail: EVM (EIP-3009) only; other families stay spent.
        if (is_array($auth)) {
            $this->unsettled[] = [$req['network'], (string) $req['amount'], (string) $auth['from'], (string) $auth['nonce'], (int) $auth['validBefore']];
        }
    }

    /**
     * Frees the expired, never-used signatures; called before paying. Each one asked about is a
     * BudgetReclaim on $events (freed, used, or unknown when the chain could not answer).
     */
    public function reclaim(?EventDispatcherInterface $events = null): void
    {
        if (null === $this->used) {
            return;
        }
        $now = Clock::now($this->clock);
        foreach ($this->unsettled as $i => [$network, $amount, $from, $nonce, $validBefore]) {
            // Still valid, or close enough that the chain's clock may disagree with
            // ours: the seller may settle it yet.
            // ponytail: fixed 60 s skew margin; read the latest block's timestamp if it bites.
            // Gone already: a concurrent reclaim() took it while this one waited on the chain.
            if (!isset($this->unsettled[$i]) || $validBefore + 60 >= $now) {
                continue;
            }
            // Taken out before asking the chain: the call may yield, and a concurrent
            // reclaim() must not free the same signature a second time.
            $entry = $this->unsettled[$i];
            unset($this->unsettled[$i]);
            try {
                $used = ($this->used)($network, $from, $nonce);
            } catch (\Throwable $e) {
                // Unknown: stays spent, asked again next time.
                $this->unsettled[] = $entry;
                Events::report($events, new BudgetReclaim(BudgetReclaim::UNKNOWN, $network, $from, $nonce, $amount, $e));
                continue;
            }
            if (false === $used) {
                $this->spent[$network] = gmp_strval(gmp_sub($this->spent[$network] ?? 0, $amount));
            }
            Events::report($events, new BudgetReclaim(false === $used ? BudgetReclaim::FREED : BudgetReclaim::USED, $network, $from, $nonce, $amount));
        }
    }

    public function spent(string $network): string
    {
        return $this->spent[$network] ?? '0';
    }
}
