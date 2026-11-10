<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The middlewares' $lookup: what the chain (EVM transaction) or the facilitator (paymentId) did with a
 * 'pending' payment. The stored state keeps the settle receipt under 'receipt'.
 *
 * true receipt: confirmed. success false: refuted (the claim is freed). NULL: unknown, still pending.
 * Never throws: any failure to ask is NULL, so a network error never frees a claim.
 */
final class ReceiptLookup
{
    /** keccak256("AuthorizationUsed(address,bytes32)"): EIP-3009's event, topics authorizer and nonce. */
    public const string AUTHORIZATION_USED = '0x98de503528ee59b575ef0c0a2576a82497bfc029a5685b209e9ec333479b10a5';

    /**
     * @param ?PaymentStatus $facilitator the facilitator a paymentId is asked about (Circle's has a status route); NULL: only the chain
     */
    public function __construct(
        private readonly ?PaymentStatus $facilitator,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $payment the buyer's payment (its EIP-3009 authorization)
     * @param array<string, mixed> $req     the requirements it paid (its asset)
     *
     * @return ?array{success: bool, transaction?: string, network?: string, payer?: ?string, errorReason?: string}
     */
    public function __invoke(array $state, array $payment = [], array $req = []): ?array
    {
        try {
            $receipt = \is_array($state['receipt'] ?? null) ? $state['receipt'] : [];
            $network = $receipt['network'] ?? null;
            if (!\is_string($network)) {
                return null;
            }
            $transaction = $receipt['transaction'] ?? '';
            if (str_starts_with($network, 'eip155:') && \is_string($transaction) && '' !== $transaction) {
                return $this->evm($network, $transaction, $receipt, $payment, $req);
            }
            $paymentId = $receipt['paymentId'] ?? null;

            return \is_string($paymentId) && '' !== $paymentId ? $this->facilitator($paymentId, $network, $receipt) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A mined success counts only when it spent this authorization: the asset's AuthorizationUsed(from,
     * nonce) is in its logs. Without it (another transaction, or no payment to compare) it is unknown.
     *
     * @param array<string, mixed> $receipt
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $req
     *
     * @return ?array{success: bool, transaction?: string, network?: string, payer?: ?string, errorReason?: string}
     */
    private function evm(string $network, string $transaction, array $receipt, array $payment, array $req): ?array
    {
        if (1 !== preg_match('/^0x[0-9a-fA-F]{64}$/', $transaction)) {
            return null;
        }
        $rpc = new Rpc($this->http, $this->requests, $this->streams, Networks::get($network)['rpc']);
        // Not mined yet: the node answers null.
        $mined = $rpc('eth_getTransactionReceipt', [$transaction]);
        if (!\is_array($mined)) {
            return null;
        }

        return match ($mined['status'] ?? null) {
            '0x1' => self::spent($mined, $payment, $req) ? ['success' => true, 'transaction' => $transaction, 'network' => $network, 'payer' => $receipt['payer'] ?? null] : null,
            '0x0' => ['success' => false, 'errorReason' => 'transaction_reverted', 'transaction' => $transaction, 'network' => $network],
            default => null,
        };
    }

    /**
     * Whether the mined receipt logs the asset's AuthorizationUsed for this payment's from and nonce.
     *
     * @param array<mixed>         $mined
     * @param array<string, mixed> $payment
     * @param array<string, mixed> $req
     */
    private static function spent(array $mined, array $payment, array $req): bool
    {
        $from = $payment['payload']['authorization']['from'] ?? null;
        $nonce = $payment['payload']['authorization']['nonce'] ?? null;
        $asset = $req['asset'] ?? null;
        if (!\is_string($from) || !\is_string($nonce) || !\is_string($asset) || !\is_array($mined['logs'] ?? null)) {
            return false;
        }
        $want = [self::AUTHORIZATION_USED, '0x'.str_pad(substr(strtolower($from), 2), 64, '0', \STR_PAD_LEFT), strtolower($nonce)];
        foreach ($mined['logs'] as $log) {
            $topics = \is_array($log) && \is_array($log['topics'] ?? null) ? array_slice(array_values($log['topics']), 0, 3) : [];
            $topics = array_map(static fn (mixed $t): string => \is_string($t) ? strtolower($t) : '', $topics);
            if ($want === $topics && \is_string($log['address'] ?? null) && 0 === strcasecmp($log['address'], $asset)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $receipt
     *
     * @return ?array{success: bool, transaction?: string, network?: string, payer?: ?string, errorReason?: string}
     */
    private function facilitator(string $paymentId, string $network, array $receipt): ?array
    {
        $status = $this->facilitator?->status($paymentId, $network);

        return match ($status['status'] ?? null) {
            'completed' => ['success' => true, 'transaction' => (string) ($status['transaction'] ?? $receipt['transaction'] ?? ''), 'network' => $network, 'payer' => $status['payer'] ?? null],
            'failed' => ['success' => false, 'errorReason' => \is_string($status['reason'] ?? null) ? $status['reason'] : 'settlement_failed', 'transaction' => '', 'network' => $network],
            default => null,
        };
    }
}
