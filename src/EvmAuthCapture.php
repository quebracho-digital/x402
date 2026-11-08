<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The `auth-capture` scheme on EVM chains, EIP-3009 path: the buyer authorizes a maximum that an escrow
 * holds; the seller later captures what it is owed and voids the rest (AuthCapture has the money path).
 *
 * What this library supports, and what it refuses on purpose:
 *
 *   - Paying (pay): any `escrow` or `authorization` offer on the EIP-3009 path, bound or unbound salt, into
 *     either commerce-payments deployment. Not Permit2 (assetTransferMethod "permit2"): it needs a one-time
 *     Permit2 approval this library does not manage.
 *   - Selling (verify): the seller as its own operator. `operatorType` "delegated" (or absent) with
 *     `captureAuthorizer` = the seller's gas wallet, no `receiverAuthorizer` and no `policy` (an unbound
 *     salt), `paymentFlow` "escrow", `captureMode` "deferred": AuthCaptureOperator sends authorize itself and
 *     captures or voids later, out of band. No third party can move the hold.
 *   - Signatures: ECDSA from an EOA only, as for `exact`. The spec asks a facilitator to accept ERC-1271
 *     and ERC-6492 smart wallets too; this library refuses them everywhere (a contract signer can change
 *     what it accepts after we checked).
 */
final class EvmAuthCapture implements Scheme
{
    /** Seconds a signature or a deadline must still have when checked, as the spec's floor. */
    public const SLACK = 6;

    /**
     * @param ?Signer   $buyer needed to pay; a seller that only verifies passes none
     * @param ?\Closure $clock returns the current Unix time; tests pin it
     */
    public function __construct(
        private readonly ?Signer $buyer = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function name(): string
    {
        return AuthCapture::SCHEME;
    }

    public function supports(string $network): bool
    {
        return (bool) preg_match('/^eip155:[1-9]\d*$/', $network);
    }

    public function pay(array $required, array $req): array
    {
        $buyer = $this->buyer ?? throw new \LogicException('Paying needs a buyer key.');
        $extra = $req['extra'] ?? [];
        $deployment = AuthCapture::deployment($extra['authCaptureEscrow'] ?? null) ?? throw new \InvalidArgumentException('Unknown authCaptureEscrow.');
        if ('eip3009' !== ($extra['assetTransferMethod'] ?? 'eip3009')) {
            throw new \InvalidArgumentException('Only the EIP-3009 path is supported.');
        }
        if ((int) ($req['maxTimeoutSeconds'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('maxTimeoutSeconds is required: it is the pre-approval expiry.');
        }
        $validBefore = $this->now() + (int) $req['maxTimeoutSeconds'];
        $saltNonce = '0x'.bin2hex(random_bytes(32));
        $bound = AuthCapture::bound($req);
        $salt = $bound ? AuthCapture::boundSalt((string) ($extra['receiverAuthorizer'] ?? AuthCapture::ZERO), (string) ($extra['policy'] ?? AuthCapture::ZERO), $saltNonce) : $saltNonce;
        $info = AuthCapture::paymentInfo($req, $buyer->address(), $salt, $validBefore);
        $authorization = [
            'from' => $buyer->address(),
            'to' => $deployment['eip3009Collector'],
            'value' => (string) $req['amount'],
            'validAfter' => '0',
            'validBefore' => (string) $validBefore,
            'nonce' => AuthCapture::nonce(AuthCapture::chainId($req['network']), $info, $deployment['escrow']),
        ];
        $payload = ['authorization' => $authorization, 'signature' => $buyer->sign(AuthCapture::digest($req, $authorization)), 'salt' => $salt];

        return [
            'x402Version' => 2,
            'resource' => $required['resource'] ?? null,
            'accepted' => $req,
            'payload' => $bound ? $payload + ['saltNonce' => $saltNonce] : $payload,
        ];
    }

    public function verify(array $payment, array $req): ?string
    {
        $payload = $payment['payload'] ?? null;
        $auth = $payload['authorization'] ?? null;
        $accepted = $payment['accepted'] ?? null;
        if (!\is_array($payload) || !\is_array($auth) || !\is_array($accepted) || !self::canonical($payload)) {
            return 'invalid_auth_capture_evm_payload_format';
        }
        $reason = self::offer($req, $this->now());
        if (null !== $reason) {
            return $reason;
        }
        $extra = $req['extra'];
        $deployment = AuthCapture::deployment($extra['authCaptureEscrow'] ?? null);
        \assert(null !== $deployment); // offer() checked it
        $now = $this->now();
        $validBefore = (int) $auth['validBefore'];
        $info = AuthCapture::paymentInfo($req, $auth['from'], $payload['salt'], $validBefore);

        return match (true) {
            ($payment['x402Version'] ?? null) !== 2 => 'invalid_x402_version',
            ($accepted['scheme'] ?? null) !== AuthCapture::SCHEME => 'invalid_auth_capture_evm_scheme',
            ($accepted['network'] ?? null) !== $req['network'] => 'invalid_auth_capture_evm_network_mismatch',
            // The buyer echoes the offer it took; a different echo is not that offer. Every other field of
            // the offer is in the nonce, which is checked against OUR requirement below.
            0 !== strcasecmp((string) ($accepted['asset'] ?? ''), $req['asset'])
              || 0 !== strcasecmp((string) ($accepted['payTo'] ?? ''), $req['payTo'])
              || (string) ($accepted['amount'] ?? '') !== (string) $req['amount'] => 'invalid_auth_capture_evm_payload_format',
            // Present only when the salt is bound: we sell unbound (offer() refuses an authorizer), so never.
            \array_key_exists('saltNonce', $payload) => 'invalid_auth_capture_evm_payload_format',
            0 !== strcasecmp($auth['to'], $deployment['eip3009Collector']) => 'invalid_auth_capture_evm_token_collector_mismatch',
            $auth['value'] !== (string) $req['amount'] => 'invalid_auth_capture_evm_amount_mismatch',
            // The collector calls receiveWithAuthorization with validAfter 0: anything else never settles.
            '0' !== $auth['validAfter'] => 'invalid_auth_capture_evm_authorization_not_yet_valid',
            $validBefore <= $now + self::SLACK => 'invalid_auth_capture_evm_authorization_expired',
            // No longer than the offer allows (+60 s clock skew): bounds the replay window, as for `exact`.
            $validBefore > $now + (int) $req['maxTimeoutSeconds'] + 60 => 'invalid_auth_capture_evm_authorization_expired',
            $validBefore > (int) $extra['captureDeadline'] => 'invalid_auth_capture_evm_deadline_ordering',
            0 !== strcasecmp((string) Signer::recover(AuthCapture::digest($req, $auth), $payload['signature']), $auth['from']) => 'invalid_auth_capture_evm_signature',
            // Recomputing the nonce from our requirement binds every PaymentInfo field: operator, receiver,
            // token, maximum, deadlines, fees. A buyer cannot sign one payment and settle another.
            0 !== strcasecmp($auth['nonce'], AuthCapture::nonce(AuthCapture::chainId($req['network']), $info, $deployment['escrow'])) => 'invalid_auth_capture_evm_nonce_mismatch',
            default => null,
        };
    }

    /**
     * Why an offer is not one this library sells, or NULL: its own requirement, checked before any payment
     * against it (the spec's checks 4 to 7 that do not depend on the payload).
     */
    public static function offer(array $req, int $now): ?string
    {
        $extra = $req['extra'] ?? null;
        if (!\is_array($extra)) {
            return 'invalid_auth_capture_evm_extra';
        }
        $address = fn (mixed $a): bool => \is_string($a) && (bool) preg_match('/^0x[0-9a-fA-F]{40}$/', $a);
        $uint = fn (mixed $n, int $max): bool => \is_int($n) && $n >= 0 && $n <= $max;
        $zero = fn (mixed $a): bool => null === $a || (\is_string($a) && 0 === strcasecmp($a, AuthCapture::ZERO));
        $timeout = (int) ($req['maxTimeoutSeconds'] ?? 0);

        return match (true) {
            ($req['scheme'] ?? null) !== AuthCapture::SCHEME => 'invalid_auth_capture_evm_scheme',
            !\is_string($req['network'] ?? null) || !preg_match('/^eip155:[1-9]\d*$/', $req['network']) => 'invalid_network',
            !preg_match('/^[1-9][0-9]*$/', (string) ($req['amount'] ?? ''))
              // uint120: the escrow's maxAmount
              || gmp_cmp((string) $req['amount'], gmp_pow(2, 120)) >= 0 => 'invalid_auth_capture_evm_extra',
            !$address($req['asset'] ?? null) || !$address($req['payTo'] ?? null) || $timeout <= 0 => 'invalid_auth_capture_evm_extra',
            empty($extra['name']) || empty($extra['version']) => 'invalid_auth_capture_evm_extra',
            null === AuthCapture::deployment($extra['authCaptureEscrow'] ?? null) => 'invalid_auth_capture_evm_extra',
            !$address($extra['captureAuthorizer'] ?? null) || !$address($extra['feeRecipient'] ?? null) => 'invalid_auth_capture_evm_extra',
            !$uint($extra['captureDeadline'] ?? null, 2 ** 48 - 1) || !$uint($extra['refundDeadline'] ?? null, 2 ** 48 - 1) => 'invalid_auth_capture_evm_extra',
            !$uint($extra['minFeeBps'] ?? null, 10000) || !$uint($extra['maxFeeBps'] ?? null, 10000) => 'invalid_auth_capture_evm_extra',
            // A fixed fee only (the spec's preferred form): a range would grant the operator a choice, and the
            // operator here is us, so there is nothing to bound. A fee needs somewhere to go.
            $extra['minFeeBps'] !== $extra['maxFeeBps'] => 'invalid_auth_capture_evm_extra',
            $extra['minFeeBps'] > 0 && $zero($extra['feeRecipient']) => 'invalid_auth_capture_evm_extra',
            'escrow' !== ($extra['paymentFlow'] ?? 'escrow') => 'invalid_auth_capture_evm_unsupported_payment_flow',
            \array_key_exists('autoCapture', $extra) && true === $extra['autoCapture'] => 'invalid_auth_capture_evm_unsupported_payment_flow',
            // We operate the hold ourselves: lifecycle runs out of band, so the spec requires "deferred" written out.
            'deferred' !== ($extra['captureMode'] ?? null) => 'invalid_auth_capture_evm_extra',
            'delegated' !== ($extra['operatorType'] ?? 'delegated') => 'invalid_auth_capture_evm_unsupported_operator_type',
            !$zero($extra['policy'] ?? null) => 'invalid_auth_capture_evm_policy',
            !$zero($extra['receiverAuthorizer'] ?? null) => 'invalid_auth_capture_evm_extra',
            'eip3009' !== ($extra['assetTransferMethod'] ?? 'eip3009') => 'invalid_auth_capture_evm_unsupported_asset_transfer_method',
            $extra['refundDeadline'] < $extra['captureDeadline'] => 'invalid_auth_capture_evm_deadline_ordering',
            // The whole window a buyer may sign for fits inside the hold.
            $now + $timeout > $extra['captureDeadline'] => 'invalid_auth_capture_evm_deadline_ordering',
            $extra['captureDeadline'] <= $now + self::SLACK => 'invalid_auth_capture_evm_capture_deadline_expired',
            default => null,
        };
    }

    /**
     * One spelling per value, as for `exact`: the salt is also a database key (the spec pins 0x + 64 hex),
     * and the nonce is what Settlement::key reads.
     */
    private static function canonical(array $payload): bool
    {
        $auth = $payload['authorization'];
        $formats = ['from' => '/^0x[0-9a-fA-F]{40}$/', 'to' => '/^0x[0-9a-fA-F]{40}$/', 'nonce' => '/^0x[0-9a-fA-F]{64}$/', 'value' => '/^(0|[1-9][0-9]{0,77})$/', 'validAfter' => '/^(0|[1-9][0-9]{0,18})$/', 'validBefore' => '/^(0|[1-9][0-9]{0,18})$/'];
        foreach ($formats as $key => $format) {
            if (!\is_string($auth[$key] ?? null) || !preg_match($format, $auth[$key])) {
                return false;
            }
        }

        return \is_string($payload['signature'] ?? null) && preg_match('/^0x[0-9a-fA-F]{130}$/', $payload['signature'])
          && \is_string($payload['salt'] ?? null) && preg_match('/^0x[0-9a-fA-F]{64}$/', $payload['salt'])
          // The same salt in two spellings would be one payment with two keys.
          && $payload['salt'] === strtolower($payload['salt']);
    }

    public function payer(array $payment): ?string
    {
        $from = $payment['payload']['authorization']['from'] ?? null;

        return \is_string($from) ? $from : null;
    }

    /**
     * The PaymentInfo a verified payment authorizes: what the seller keeps to capture or void it later.
     * Call it only after verify() passed.
     *
     * @return array<string, string|int>
     */
    public static function paymentInfoOf(array $payment, array $req): array
    {
        $auth = $payment['payload']['authorization'];

        return AuthCapture::paymentInfo($req, $auth['from'], $payment['payload']['salt'], (int) $auth['validBefore']);
    }

    public function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }
}
