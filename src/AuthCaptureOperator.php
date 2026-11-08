<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The seller as its own `auth-capture` operator on one EVM network: it puts a buyer's payment on hold in
 * the escrow (authorize, as the middlewares' settle) and later captures it or voids it, from its own gas
 * wallet. No facilitator between us and the escrow: only `PaymentInfo.operator` (our gas wallet) can move
 * the hold, and the buyer can take it back alone after `captureDeadline`.
 *
 * The spec calls this `operatorType: "delegated"` with no receiver authorizer: the facilitator (us) relays
 * the collect (authorize) and runs the lifecycle out of band, so offers say `captureMode: "deferred"`.
 *
 * The escrow is the record. Whatever we lost (a crash, a timeout), paymentState(hash) says how the hold
 * stands: capturable (still held), refundable (captured), both zero (voided, or reclaimed by the buyer).
 * A capture after a void, or a void after a capture, reverts: the chain never lets both happen.
 *
 * The gas wallet pays gas, nothing else: the hold goes from the buyer to the escrow to payTo.
 * Operates the v1.1 escrow only (its capture takes a feeAmount; v1.0's takes feeBps).
 */
final class AuthCaptureOperator implements Facilitator, HoldOperator
{
    public function __construct(
        private readonly ChainSender $sender,
        private readonly EvmAuthCapture $scheme = new EvmAuthCapture(),
    ) {
    }

    public function network(): string
    {
        return $this->sender->network();
    }

    /**
     * The offer to publish for a price: this operator's address as captureAuthorizer, the hold's deadlines,
     * and an optional fixed platform fee (`feeBps` of every capture to `feeRecipient`).
     *
     * @param array $exact           an `exact` offer for the same price (Networks::requirements): network,
     *                               amount, asset, payTo, maxTimeoutSeconds and the token's EIP-712 domain
     * @param int   $captureDeadline Unix time: last moment we can capture; the buyer may reclaim from then
     * @param int   $refundDeadline  Unix time, at least $captureDeadline (we do not refund: it may equal it)
     */
    public function offer(array $exact, int $captureDeadline, ?int $refundDeadline = null, int $feeBps = 0, string $feeRecipient = AuthCapture::ZERO): array
    {
        if ($exact['network'] !== $this->sender->network()) {
            throw new \InvalidArgumentException("This operator runs on {$this->sender->network()}, not {$exact['network']}.");
        }

        return [
            'scheme' => AuthCapture::SCHEME,
            'network' => $exact['network'],
            'amount' => $exact['amount'],
            'asset' => $exact['asset'],
            'payTo' => $exact['payTo'],
            'maxTimeoutSeconds' => $exact['maxTimeoutSeconds'],
            'extra' => [
                'name' => $exact['extra']['name'],
                'version' => $exact['extra']['version'],
                'authCaptureEscrow' => AuthCapture::V1_1['escrow'],
                'captureAuthorizer' => $this->sender->address(),
                'operatorType' => 'delegated',
                'paymentFlow' => 'escrow',
                'captureMode' => 'deferred',
                'captureDeadline' => $captureDeadline,
                'refundDeadline' => $refundDeadline ?? $captureDeadline,
                'minFeeBps' => $feeBps,
                'maxFeeBps' => $feeBps,
                'feeRecipient' => $feeRecipient,
                'assetTransferMethod' => 'eip3009',
            ],
        ];
    }

    public function verify(array $payment, array $req): array
    {
        $result = ['isValid' => false, 'payer' => $payment['payload']['authorization']['from'] ?? null];
        $reason = $this->mismatch($payment, $req);
        if (null === $reason) {
            try {
                $this->sender->call(AuthCapture::V1_1['escrow'], $this->authorizeCall($payment, $req));

                return ['isValid' => true] + $result;
            } catch (FacilitatorException $e) {
                throw $e;
            } catch (\RuntimeException) {
                $reason = 'invalid_auth_capture_evm_simulation_failed';
            }
        }

        return ['invalidReason' => $reason] + $result;
    }

    /**
     * Puts the payment on hold: AuthCaptureEscrow.authorize, the whole amount (the spec: collecting less
     * consumes the buyer's single-use authorization and caps the payment below what it agreed to).
     *
     * @param ?\Closure $reference fn(string $transaction): bool, as ChainFacilitator::settle
     */
    public function settle(array $payment, array $req, ?\Closure $reference = null): array
    {
        $result = ['success' => false, 'transaction' => '', 'network' => $req['network'], 'payer' => $payment['payload']['authorization']['from'] ?? null];
        $reason = $this->mismatch($payment, $req);
        if (null !== $reason) {
            return ['errorReason' => $reason] + $result;
        }

        return $this->sender->send(AuthCapture::V1_1['escrow'], $this->authorizeCall($payment, $req), $reference) + $result;
    }

    /**
     * How the hold stands, read from the escrow.
     *
     * @return array{collected: bool, capturable: string, refundable: string}
     *
     * @throws FacilitatorException the node cannot be reached
     * @throws \RuntimeException    the node answered with an error, or not three words
     */
    public function state(array $info): array
    {
        $this->ours($info);
        try {
            return AuthCapture::state($this->sender->call(AuthCapture::V1_1['escrow'], AuthCapture::stateCall($this->hash($info))));
        } catch (\UnexpectedValueException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Pays the receiver $amount of the hold (all of it when NULL), less the published fee.
     *
     * @param ?\Closure $reference fn(string $transaction): bool, called with the hash before the send
     *
     * @return array{success: bool, transaction: string, errorReason?: string}
     */
    public function capture(array $info, ?string $amount = null, ?\Closure $reference = null): array
    {
        $this->ours($info);
        $amount ??= (string) $info['maxAmount'];
        if (!preg_match('/^[1-9][0-9]*$/', $amount)) {
            throw new \InvalidArgumentException("Not an amount to capture: $amount.");
        }

        return $this->sender->send(AuthCapture::V1_1['escrow'], AuthCapture::captureCall($info, $amount, AuthCapture::fee($info, $amount), (string) $info['feeReceiver']), $reference);
    }

    /**
     * Gives the whole remaining hold back to the payer.
     *
     * @param ?\Closure $reference fn(string $transaction): bool, called with the hash before the send
     *
     * @return array{success: bool, transaction: string, errorReason?: string}
     */
    public function void(array $info, ?\Closure $reference = null): array
    {
        $this->ours($info);

        return $this->sender->send(AuthCapture::V1_1['escrow'], AuthCapture::voidCall($info), $reference);
    }

    /**
     * The escrow's key for a payment on this network.
     */
    public function hash(array $info): string
    {
        return AuthCapture::hash(AuthCapture::chainId($this->sender->network()), $info);
    }

    /**
     * Why we do not put this payment on hold, or NULL. After NULL, authorizeCall() does not throw.
     */
    private function mismatch(array $payment, array $req): ?string
    {
        return match (true) {
            ($req['network'] ?? null) !== $this->sender->network() => 'invalid_network',
            // The spec: a delegated captureAuthorizer must be an address we submit from.
            0 !== strcasecmp((string) ($req['extra']['captureAuthorizer'] ?? ''), $this->sender->address()) => 'invalid_auth_capture_evm_operator_not_admitted',
            // We operate v1.1 only: an offer pinned to v1.0 is not ours to hold.
            AuthCapture::V1_1 !== AuthCapture::deployment($req['extra']['authCaptureEscrow'] ?? null) => 'invalid_auth_capture_evm_extra',
            default => $this->scheme->verify($payment, $req),
        };
    }

    private function authorizeCall(array $payment, array $req): string
    {
        return AuthCapture::authorizeCall(EvmAuthCapture::paymentInfoOf($payment, $req), (string) $req['amount'], AuthCapture::V1_1['eip3009Collector'], $payment['payload']['signature']);
    }

    /**
     * A lifecycle call only for a payment we operate: the escrow would revert anyway (InvalidSender), but
     * at the price of a transaction's gas estimate, and with a less telling error.
     */
    private function ours(array $info): void
    {
        if (0 !== strcasecmp((string) ($info['operator'] ?? ''), $this->sender->address())) {
            throw new \InvalidArgumentException('This payment is held for another operator.');
        }
    }
}
