<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * What both middlewares do around the handler: claim a payment once, and
 * read a facilitator's answer without trusting its shape.
 */
final class Settlement
{
    /**
     * The payment's identity: what the chain spends once, not its signature.
     *
     * EVM: network + payer + EIP-3009 nonce (re-signing the same nonce must
     * not buy a second delivery). Other families: the whole payload.
     */
    public static function key(array $payment): string
    {
        $auth = $payment['payload']['authorization'] ?? null;
        $id = is_array($auth)
          ? [$payment['accepted']['network'] ?? '', strtolower((string) ($auth['from'] ?? '')), strtolower((string) ($auth['nonce'] ?? ''))]
          : $payment['payload'] ?? null;

        return hash('sha256', json_encode($id, JSON_THROW_ON_ERROR));
    }

    /**
     * A receipt; success only when the facilitator said `success: true`.
     *
     * @param ?\Closure $settle
     *                          NULL is mock mode: a receipt without a transaction, nothing moved
     */
    public static function run(?\Closure $settle, array $payment, array $req): array
    {
        if (null === $settle) {
            return ['success' => true, 'transaction' => '', 'network' => $req['network'], 'payer' => $payment['payload']['authorization']['from'] ?? null];
        }
        try {
            $receipt = $settle($payment, $req);
        } catch (FacilitatorException) {
            // Not the buyer's fault: the HTTP transport answers 502, the claim is freed.
            $receipt = ['success' => false, 'errorReason' => Sale::UNAVAILABLE];
        } catch (\Throwable) {
            $receipt = null;
        }
        if (is_array($receipt) && ($receipt['success'] ?? null) === true) {
            return $receipt;
        }
        $reason = is_array($receipt) && is_string($receipt['errorReason'] ?? null) ? $receipt['errorReason'] : 'unexpected_settle_error';
        // Kept so a broadcast that has not confirmed yet (settlement_pending) can be reconciled.
        $tx = is_array($receipt) && is_string($receipt['transaction'] ?? null) ? $receipt['transaction'] : '';

        $failed = ['success' => false, 'errorReason' => $reason, 'transaction' => $tx, 'network' => $req['network']];

        return is_array($receipt) && is_string($receipt['paymentId'] ?? null) ? $failed + ['paymentId' => $receipt['paymentId']] : $failed;
    }
}
