<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Our own facilitator on one EVM network: verifies and settles `exact`
 * payments on chain, no third party.
 *
 * The buyer signed an EIP-3009 transferWithAuthorization; the gas wallet
 * sends it to the token. The token judges the signature, the balance, the
 * time window and the nonce: verifying is that call simulated (eth_call),
 * settling is the same call sent. Here only what the token cannot know:
 * that it pays our seller our price.
 *
 * The gas wallet pays gas, nothing else: sales go from the buyer to payTo.
 */
final class ChainFacilitator implements Facilitator
{
    // transferWithAuthorization(address,address,uint256,uint256,uint256,bytes32,uint8,bytes32,bytes32)
    private const TRANSFER_WITH_AUTHORIZATION = '0xe3ee160e';

    // Seconds a payment must stay valid after verifying: settling comes next.
    private const MARGIN = 3;

    /**
     * @param string   $network
     *                             The CAIP-2 network $rpc talks to; other networks are refused
     * @param \Closure $serialize
     *                             fn(string $name, callable $run): mixed, runs $run holding a lock named
     *                             $name across every process (flock, DB, Redis): one sender, one nonce
     *                             at a time, or parallel settles replace each other's transactions
     * @param ?string  $maxGasCost
     *                             Wei: no settle whose gas limit × max fee costs more (a buyer can make
     *                             our transaction revert and burn gas); NULL is no cap. The caller sets
     *                             it (the app: from its config), no default here
     */
    private readonly ChainSender $sender;

    public function __construct(
        Rpc $rpc,
        Signer $gas,
        private readonly string $network,
        \Closure $serialize,
        ?string $maxGasCost = null,
        int $pollDelayMs = 500,
        int $pollAttempts = 40,
    ) {
        $this->sender = new ChainSender($rpc, $gas, $network, $serialize, $maxGasCost, $pollDelayMs, $pollAttempts);
    }

    public function verify(array $payment, array $req): array
    {
        $result = ['isValid' => false, 'payer' => $payment['payload']['authorization']['from'] ?? null];
        $reason = $this->mismatch($payment, $req);
        if (null === $reason) {
            try {
                $this->sender->call($req['asset'], self::calldata($payment));

                return ['isValid' => true] + $result;
            } catch (FacilitatorException $e) {
                throw $e;
            } catch (\RuntimeException $e) {
                $reason = $e->getMessage();
            }
        }

        return ['invalidReason' => $reason] + $result;
    }

    /**
     * @param ?\Closure $reference
     *                             fn(string $transaction): bool, called with the hash before the send (Settlement::run
     *                             passes the one Sale gives, which stores it in the ledger). FALSE or a throw sends
     *                             nothing: 'reference_unavailable', no transaction, the claim is freed.
     */
    public function settle(array $payment, array $req, ?\Closure $reference = null): array
    {
        $result = ['success' => false, 'transaction' => '', 'network' => $req['network'], 'payer' => $payment['payload']['authorization']['from'] ?? null];
        $reason = $this->mismatch($payment, $req);
        if (null !== $reason) {
            return ['errorReason' => $reason] + $result;
        }

        return $this->sender->send($req['asset'], self::calldata($payment), $reference) + $result;
    }

    /**
     * Why the payment does not pay our price to our seller here, or NULL.
     * After NULL, calldata() does not throw.
     */
    private function mismatch(array $payment, array $req): ?string
    {
        if ($req['network'] !== $this->network) {
            return 'invalid_network';
        }
        try {
            self::calldata($payment);
        } catch (InvalidPaymentException $e) {
            return 'invalid_exact_evm_payload';
        }
        $auth = $payment['payload']['authorization'];
        $now = time();

        return match (true) {
            0 !== strcasecmp((string) $auth['to'], (string) $req['payTo']) => 'invalid_exact_evm_payload_recipient_mismatch',
            (string) $auth['value'] !== (string) $req['amount'] => 'invalid_exact_evm_payload_authorization_value',
            (int) $auth['validAfter'] > $now => 'invalid_exact_evm_payload_authorization_valid_after',
            (int) $auth['validBefore'] < $now + self::MARGIN => 'invalid_exact_evm_payload_authorization_valid_before',
            default => null,
        };
    }

    /**
     * The transferWithAuthorization call, from the buyer's payload.
     *
     * @throws InvalidPaymentException
     *                                 Missing or malformed fields, or not a 65-byte signature
     */
    public static function calldata(array $payment): string
    {
        $auth = $payment['payload']['authorization'] ?? [];
        $signature = $payment['payload']['signature'] ?? null;
        // ponytail: EOA signatures only (r ‖ s ‖ v); smart wallets (ERC-1271) need the bytes overload.
        if (!is_array($auth) || !is_string($signature) || !preg_match('/^0x[0-9a-fA-F]{130}$/', $signature)) {
            throw new InvalidPaymentException('The signature is not 65 bytes.');
        }
        $patterns = ['from' => '/^0x[0-9a-fA-F]{40}$/', 'to' => '/^0x[0-9a-fA-F]{40}$/', 'value' => '/^(0|[1-9]\d*)$/', 'validAfter' => '/^(0|[1-9]\d*)$/', 'validBefore' => '/^(0|[1-9]\d*)$/', 'nonce' => '/^0x[0-9a-fA-F]{64}$/'];
        $words = [];
        foreach ($patterns as $field => $pattern) {
            $value = $auth[$field] ?? null;
            if (!is_string($value) || !preg_match($pattern, $value)) {
                throw new InvalidPaymentException("The authorization's $field is malformed.");
            }
            $words[] = str_starts_with($value, '0x') ? Rpc::word($value) : Rpc::word(gmp_strval(gmp_init($value, 10), 16));
        }
        $v = hexdec(substr($signature, 130, 2));
        $words[] = Rpc::word(dechex($v < 27 ? $v + 27 : $v));
        $words[] = substr($signature, 2, 64);
        $words[] = substr($signature, 66, 64);

        return self::TRANSFER_WITH_AUTHORIZATION.implode('', $words);
    }
}
