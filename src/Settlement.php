<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * What both middlewares do around the handler: claim a payment once, and
 * read a facilitator's answer without trusting its shape.
 */
final class Settlement
{
    public const string UNAVAILABLE = 'facilitator_unavailable';
    public const string UNEXPECTED_VERIFY = 'unexpected_verify_error';
    /** How long a claim or a state lives, at most: seconds. */
    public const int TTL = 600;
    /** How long a 'settling' or 'pending' state that holds a transaction lives: a day to reconcile it. */
    public const int REFERENCED_TTL = 86400;
    /**
     * How long an open entry (PaymentLedger::open) lives: three days, so a reconciler still sees it after the
     * 24 h in which it may resolve it, and marks it needs_review.
     */
    public const int OPEN_TTL = 259200;
    /** The largest response body kept in the ledger, in bytes. */
    public const int MAX_BODY = 1048576;

    /** The prefix of the ledger claim one retry holds while it runs an upfront handler that has not delivered. */
    public const string RUN = 'run:';

    /**
     * Settle failures after which the money may still move: a broadcast not confirmed yet, a facilitator that
     * went silent after it may have sent, an answer that cannot be read. Sale keeps the payment's claim for
     * exactly these (and for any failure that carries a transaction); every other failure moved nothing.
     * Public so a seller that wraps the settle (a job store, say) decides "nothing moved" with the same list.
     */
    public const array MAY_HAVE_MOVED = ['settlement_pending', self::UNAVAILABLE, 'unexpected_settle_error', 'invalid_settle_response'];

    /**
     * The payment's identity: what the chain spends once, not its signature. The payment's scheme says what
     * that is (SpendsOnce): EVM and Casper, network + payer + nonce; Solana, network + the signed message;
     * NEAR, network + sender + key + nonce. A scheme that does not say is identified by its whole payload.
     *
     * The scheme follows the network, not the fields present: schemes ignore fields they do not read, so a
     * NEAR payment carrying an `authorization` would otherwise get a fresh key.
     *
     * @param iterable<Scheme> $schemes the caller's own schemes, asked before the library's; needed only for
     *                                  a scheme of your own that implements SpendsOnce
     */
    public static function key(array $payment, iterable $schemes = []): string
    {
        $id = Schemes::find((string) ($payment['accepted']['network'] ?? ''), $schemes, SpendsOnce::class)?->spent($payment);

        return hash('sha256', json_encode($id ?? ($payment['payload'] ?? null), JSON_THROW_ON_ERROR));
    }

    /**
     * Mock mode, opted into by name: signatures are verified for real, nothing
     * moves on chain. The receipt has no transaction and says `mock`.
     */
    public static function mock(): \Closure
    {
        return fn (array $payment, array $req): array => ['success' => true, 'transaction' => '', 'network' => $req['network'], 'payer' => $payment['payload']['authorization']['from'] ?? null, 'extensions' => ['mock' => true]];
    }

    /**
     * The middlewares' $settle as a closure: a Facilitator's settle (ChainFacilitator's takes the reference
     * closure too, see run()), or the closure as it is.
     */
    public static function settler(\Closure|Facilitator $settle): \Closure
    {
        return $settle instanceof Facilitator ? $settle->settle(...) : $settle;
    }

    /**
     * The middlewares' $verify from a Facilitator: NULL when its /verify says the payment is good, else its
     * reason (invalid_payment when it gives none). A FacilitatorException goes through: the middlewares answer
     * facilitator_unavailable.
     */
    public static function verifier(Facilitator $facilitator): \Closure
    {
        return static function (array $payment, array $req) use ($facilitator): ?string {
            $out = $facilitator->verify($payment, $req);

            return true === $out['isValid'] ? null : (\is_string($out['invalidReason'] ?? null) && '' !== $out['invalidReason'] ? $out['invalidReason'] : 'invalid_payment');
        };
    }

    /**
     * A receipt; success only when the facilitator said `success: true`.
     *
     * @param \Closure    $settle    fn(array $payment, array $req): array
     * @param ?\Closure   $reference fn(string $transaction): bool, given to $settle as a third argument: a closure
     *                               that takes it (ChainFacilitator's settle(...)) stores its reference before sending;
     *                               one that does not ignores it (PHP drops extra arguments)
     * @param ?\Throwable $error     set to what $settle threw, for PaymentOutcome->error (the receipt has only a reason)
     */
    public static function run(\Closure $settle, array $payment, array $req, ?\Closure $reference = null, ?\Throwable &$error = null): array
    {
        $error = null;
        try {
            $receipt = null === $reference ? $settle($payment, $req) : $settle($payment, $req, $reference);
        } catch (FacilitatorException $e) {
            // Not the buyer's fault: the HTTP transport answers 502. It may have sent the
            // transaction before going silent, so the claim is kept.
            $receipt = ['success' => false, 'errorReason' => self::UNAVAILABLE];
            $error = $e;
        } catch (\Throwable $e) {
            $receipt = null;
            $error = $e;
        }
        if (is_array($receipt) && ($receipt['success'] ?? null) === true) {
            return self::settled($receipt);
        }
        $reason = is_array($receipt) && is_string($receipt['errorReason'] ?? null) ? $receipt['errorReason'] : 'unexpected_settle_error';
        // Kept so a broadcast that has not confirmed yet (settlement_pending) can be reconciled.
        $tx = is_array($receipt) && is_string($receipt['transaction'] ?? null) ? $receipt['transaction'] : '';

        $failed = ['success' => false, 'errorReason' => $reason, 'transaction' => $tx, 'network' => $req['network']];

        return is_array($receipt) && is_string($receipt['paymentId'] ?? null) ? $failed + ['paymentId' => $receipt['paymentId']] : $failed;
    }

    /**
     * A settled receipt with the spec's fields, each of its type, in this order. A field of another
     * type is dropped; so are extensions that JSON cannot encode (INF, too deep): the money moved,
     * so success stays and the header encode must not throw later.
     */
    public static function settled(array $receipt): array
    {
        $out = ['success' => true];
        foreach (['transaction', 'network'] as $field) {
            if (is_string($receipt[$field] ?? null)) {
                $out[$field] = $receipt[$field];
            }
        }
        if (array_key_exists('payer', $receipt) && (is_string($receipt['payer']) || null === $receipt['payer'])) {
            $out['payer'] = $receipt['payer'];
        }
        foreach (['errorReason', 'errorMessage', 'paymentId'] as $field) {
            if (is_string($receipt[$field] ?? null)) {
                $out[$field] = $receipt[$field];
            }
        }
        // Atomic units, a non-negative integer as a string (an int could already have lost precision).
        if (is_string($receipt['amount'] ?? null) && 1 === preg_match('/^\d+$/D', $receipt['amount'])) {
            $out['amount'] = $receipt['amount'];
        }
        if (array_key_exists('extensions', $receipt)) {
            try {
                json_encode($receipt['extensions'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $out['extensions'] = $receipt['extensions'];
            } catch (\JsonException) {
                // Dropped: the receipt still settles.
            }
        }

        return $out;
    }

    /**
     * How long a claim or state lives: until the payment expires (when its scheme can tell: Expires), at most
     * TTL seconds. A 'settling' or 'pending' state with a transaction lives REFERENCED_TTL: its money may have
     * moved, and the hash is what a retry (the lookup) or a person reconciles it with.
     *
     * @param array<string, mixed> $state
     * @param iterable<Scheme>     $schemes the seller's own schemes, asked before the library's
     */
    public static function ttl(array $payment, array $state = [], iterable $schemes = []): int
    {
        return self::ttlUntil(Schemes::find((string) ($payment['accepted']['network'] ?? ''), $schemes, Expires::class)?->expiresAt($payment), $state);
    }

    /**
     * ttl() for a payment known only by its expiry (a reconciler's reference): NULL when it has none.
     *
     * @param array<string, mixed> $state
     */
    public static function ttlUntil(?int $expiresAt, array $state = []): int
    {
        $tx = is_array($state['receipt'] ?? null) ? ($state['receipt']['transaction'] ?? null) : null;
        if (\in_array($state['status'] ?? null, ['settling', 'pending'], true) && is_string($tx) && '' !== $tx) {
            return self::REFERENCED_TTL;
        }

        return min(self::TTL, max(1, null === $expiresAt ? self::TTL : $expiresAt - time()));
    }

    /**
     * The response a stored state answers with (its receipt is added by the caller).
     */
    public static function restore(array $stored, ResponseFactoryInterface $responses, StreamFactoryInterface $streams): ResponseInterface
    {
        $response = $responses->createResponse($stored['status']);
        foreach ($stored['headers'] as $name => $value) {
            $response = $response->withHeader((string) $name, (string) $value);
        }

        return $response->withBody($streams->createStream((string) base64_decode($stored['body'], true)));
    }
}
