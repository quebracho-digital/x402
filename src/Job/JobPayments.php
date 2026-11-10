<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

use Quebracho\X402\Expires;
use Quebracho\X402\PaymentLedger;
use Quebracho\X402\Reconciler;
use Quebracho\X402\Scheme;
use Quebracho\X402\Schemes;
use Quebracho\X402\Settlement;

/**
 * How a job's payment ended, when the request that sent it is gone: settled, released (it never moved and
 * cannot any more) or unknown. Asked by JobReconciler about a `settling` job (its deposit) or a `delivering`
 * one (its `_result` payment), and by the `_result` tool when a buyer comes back to a `delivering` job.
 *
 * Two sources, in this order:
 *
 *   the ledger  what the library wrote about the payment (Settlement::key): settled, delivered or handling
 *               means the money moved; claimed, settling or pending means it is in flight, or is the payment
 *               reconciler's (Reconciler) to resolve first. No state at all is the hard case: the
 *               ledger forgets a state when its TTL ends (Settlement::ttl), so "nothing" means "released" only while
 *               every state written since the job started waiting would still be alive.
 *   the chain   after that, the token's authorizationState(from, nonce) (EIP-3009, AuthorizationState):
 *               used means it moved; unused once validBefore has passed means it never can. Only from the cron
 *               (a request does not wait on an RPC node), and only for EVM: anything else stays unknown.
 *               Never with a mock facilitator (Settlement::mock()): nothing moves on chain there, so "unused" would
 *               discard a job whose mock deposit "settled".
 *
 * Known limits:
 *
 *   - "used" is not proof that WE were paid. EIP-3009 nonces are the payer's: the buyer can burn the nonce
 *     with cancelAuthorization, or spend it on another transferWithAuthorization to itself, and the token then
 *     says used all the same. A buyer who does that while our settle is ambiguous, and waits for the ledger to
 *     forget it, gets the job run for the price of a gas fee. The fix is to find the transaction that used the
 *     nonce (its AuthorizationUsed event, as Reconciler does) and check that its receipt logs a Transfer
 *     of the asset from the payer to our payTo for the price. The job row keeps neither payTo nor amount, and the
 *     log search is a second RPC round trip over a bounded block range, so this does not.
 *   - "no state" is read as "released" only while the ledger keeps what it was given. A ledger that drops
 *     keys early (Redis with an eviction policy, or restarted without persistence) turns a paid state into no
 *     state: a paid deposit's job is then discarded, and a paid `_result` goes back to ready (paid twice). Run
 *     Redis with maxmemory-policy noeviction and appendonly yes (AOF) for this ledger.
 */
final class JobPayments
{
    public const string SETTLED = 'settled';
    public const string RELEASED = 'released';
    public const string UNKNOWN = 'unknown';

    /** @var ?\Closure(string, string, string): bool */
    private readonly ?\Closure $authorizationUsed;

    /**
     * @param ?\Closure(string, string, string): bool $authorizationUsed (network, from, nonce): whether the
     *                                                                   chain spent it; throws when it cannot tell
     * @param string                                  $facilitator       "mock" (a seller that settles with Settlement::mock()) never asks the chain
     */
    public function __construct(
        private readonly PaymentLedger $ledger,
        ?\Closure $authorizationUsed = null,
        string $facilitator = '',
    ) {
        $this->authorizationUsed = 'mock' === $facilitator ? null : $authorizationUsed;
    }

    /**
     * What the job keeps about a payment: enough to ask the ledger (its key) and the chain (network, nonce,
     * validBefore; the payer is the job's). Never the signature: a job row must not be a payment someone could
     * resubmit.
     *
     * @param iterable<Scheme> $schemes the seller's own schemes, asked before the library's
     *
     * @return array{key: string, network: ?string, nonce: ?string, validBefore: ?int}
     */
    public static function ref(array $payment, iterable $schemes = []): array
    {
        $auth = \is_array($payment['payload']['authorization'] ?? null) ? $payment['payload']['authorization'] : [];
        $network = $payment['accepted']['network'] ?? null;

        return [
            'key' => Settlement::key($payment, $schemes),
            'network' => \is_string($network) ? $network : null,
            'nonce' => \is_string($auth['nonce'] ?? null) ? $auth['nonce'] : null,
            'validBefore' => Schemes::find(\is_string($network) ? $network : '', $schemes, Expires::class)?->expiresAt($payment),
        ];
    }

    /**
     * @param array{key: string, network?: ?string, nonce?: ?string, validBefore?: ?int} $ref
     * @param string                                                                     $payer who signed it (the job's payer)
     * @param int                                                                        $since no state about this payment was written before
     *                                                                                          it (the caller errs early, never late)
     * @param bool                                                                       $chain whether the chain may be asked
     *
     * @return self::SETTLED|self::RELEASED|self::UNKNOWN
     */
    public function fate(array $ref, string $payer, int $since, int $now, bool $chain): string
    {
        $status = $this->ledger->get($ref['key'])['status'] ?? null;
        if (\in_array($status, ['settled', 'delivered', 'handling'], true)) {
            // 'handling' comes only after a settle that succeeded (upfront: the tool was about to run).
            return self::SETTLED;
        }
        if (null !== $status) {
            return self::UNKNOWN;
        }
        $validBefore = $ref['validBefore'] ?? null;
        // A state lives at least to min(validBefore, written + Settlement::TTL) (Settlement::ttl; one with a transaction,
        // a day). Every state about this payment written from $since on is then alive until
        // min(validBefore, $since + Settlement::TTL): inside that, no state means the library released it, a refusal
        // that moved nothing.
        if (\is_int($validBefore) && $now < min($validBefore, $since + Settlement::TTL)) {
            return self::RELEASED;
        }
        $network = $ref['network'] ?? null;
        $nonce = $ref['nonce'] ?? null;
        if (!$chain || null === $this->authorizationUsed || !\is_string($network) || !str_starts_with($network, 'eip155:') || !\is_string($nonce)) {
            return self::UNKNOWN;
        }
        try {
            $used = ($this->authorizationUsed)($network, $payer, $nonce);
        } catch (\Throwable) {
            // A node that cannot answer proves nothing either way.
            return self::UNKNOWN;
        }
        if ($used) {
            return self::SETTLED;
        }

        // Unused: it cannot move once it expired (a minute more for clocks and blocks). Before that, anyone
        // holding the signature (the facilitator it was sent to) could still submit it.
        return \is_int($validBefore) && $now > $validBefore + Reconciler::GRACE ? self::RELEASED : self::UNKNOWN;
    }
}
