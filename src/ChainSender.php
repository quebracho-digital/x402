<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Sends one contract call from a gas wallet and waits for its receipt: what ChainFacilitator (an `exact`
 * settle) and AuthCaptureOperator (authorize, capture, void) share.
 *
 * The hash is known before the send and handed to $reference first, so a send that times out, or a crash
 * after broadcasting, still leaves a transaction to reconcile. A send that may have been broadcast is never
 * reported as a refusal: it is `settlement_pending`.
 */
final class ChainSender
{
    /**
     * @param \Closure $serialize  fn(string $name, callable $run): mixed, runs $run holding a lock named $name
     *                             across every process: one sender, one nonce at a time
     * @param ?string  $maxGasCost wei: no call whose gas limit × max fee costs more; NULL is no cap
     */
    public function __construct(
        private readonly Rpc $rpc,
        private readonly Signer $gas,
        private readonly string $network,
        private readonly \Closure $serialize,
        private readonly ?string $maxGasCost = null,
        private readonly int $pollDelayMs = 500,
        private readonly int $pollAttempts = 40,
    ) {
        if (!preg_match('/^eip155:[1-9]\d*$/', $network)) {
            throw new \InvalidArgumentException("Not an EVM network: $network");
        }
    }

    public function network(): string
    {
        return $this->network;
    }

    public function address(): string
    {
        return $this->gas->address();
    }

    /**
     * The call simulated from the gas wallet (eth_call): its return data.
     *
     * @throws \RuntimeException    it reverts (the node's message)
     * @throws FacilitatorException the node cannot be reached
     */
    public function call(string $to, string $data): string
    {
        $answer = ($this->rpc)('eth_call', [['from' => $this->gas->address(), 'to' => $to, 'data' => $data], 'latest']);

        return \is_string($answer) ? $answer : throw new FacilitatorException('eth_call: not a hex answer');
    }

    /**
     * Sends the call and waits for its receipt.
     *
     * @param ?\Closure $reference fn(string $transaction): bool, called with the hash before the send; FALSE or a
     *                             throw sends nothing ('reference_unavailable')
     *
     * @return array{success: bool, transaction: string, errorReason?: string}
     */
    public function send(string $to, string $data, ?\Closure $reference = null): array
    {
        $rpc = $this->rpc;
        $call = ['from' => $this->gas->address(), 'to' => $to, 'data' => $data];
        // Set as soon as the hash exists, before the send: if the lock fails after the send, the hash is still here.
        $transaction = '';
        $send = function () use ($rpc, $call, $reference, &$transaction): array {
            $base = Rpc::number($rpc('eth_gasPrice', []));
            $tip = Rpc::number($rpc('eth_maxPriorityFeePerGas', []));
            // Twice today's price, so a busier block still takes it.
            $maxFee = gmp_mul($base, 2);
            // Its estimate and a fifth more. A revert here is a bad call.
            $limit = gmp_div_q(gmp_mul(Rpc::number($rpc('eth_estimateGas', [$call])), 12), 10);
            if (null !== $this->maxGasCost && gmp_cmp(gmp_mul($limit, $maxFee), gmp_init($this->maxGasCost, 10)) > 0) {
                throw new \RuntimeException('gas_too_expensive');
            }
            $raw = Transfer::sign($this->gas, [
                'chainId' => explode(':', $this->network)[1],
                'nonce' => gmp_strval(Rpc::number($rpc('eth_getTransactionCount', [$this->gas->address(), 'pending']))),
                'maxPriorityFeePerGas' => gmp_strval(gmp_cmp($tip, $maxFee) > 0 ? $maxFee : $tip),
                'maxFeePerGas' => gmp_strval($maxFee),
                'gas' => gmp_strval($limit),
                'token' => $call['to'],
                'data' => $call['data'],
            ]);
            $hash = Eip712::keccak(Eip712::raw($raw));
            // Stored before it can move money: a crash after the send still leaves the hash to reconcile.
            try {
                $kept = null === $reference || true === $reference($hash);
            } catch (\Throwable) {
                $kept = false;
            }
            if (!$kept) {
                throw new \RuntimeException('reference_unavailable');
            }
            $transaction = $hash;
            try {
                $rpc('eth_sendRawTransaction', [$raw]);
            } catch (\RuntimeException) {
                // Maybe broadcast ("already known", a timeout): pending, never a refusal.
                return [$hash, false];
            }

            return [$hash, true];
        };
        try {
            // ponytail: one lock per network serializes its sends; rotate gas wallets if that ever limits throughput.
            [$hash, $sent] = ($this->serialize)("x402.chain.$this->network", $send);
        } catch (\Throwable $e) {
            // The lock failed after the send (its release, say): the money moved, so the claim stays.
            if ('' !== $transaction) {
                return ['success' => false, 'transaction' => $transaction, 'errorReason' => 'settlement_pending'];
            }

            // Nothing was sent: a refusal frees the claim.
            return ['success' => false, 'transaction' => '', 'errorReason' => $e instanceof \RuntimeException ? $e->getMessage() : 'settle_refused'];
        }
        if (!$sent) {
            return ['success' => false, 'transaction' => $hash, 'errorReason' => 'settlement_pending'];
        }

        for ($i = 0; $i < $this->pollAttempts; ++$i) {
            try {
                $receipt = $rpc('eth_getTransactionReceipt', [$hash]);
            } catch (\RuntimeException) {
                $receipt = null;
            }
            if (\is_array($receipt)) {
                return '0x1' === ($receipt['status'] ?? '')
                  ? ['success' => true, 'transaction' => $hash]
                  : ['success' => false, 'transaction' => $hash, 'errorReason' => 'transaction_reverted'];
            }
            usleep($this->pollDelayMs * 1000);
        }

        // Sent, not mined in time: the transaction is kept to reconcile.
        return ['success' => false, 'transaction' => $hash, 'errorReason' => 'settlement_pending'];
    }
}
