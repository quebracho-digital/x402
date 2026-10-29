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
     * not buy a second delivery). Solana: network + the signed message. NEAR: network + sender + key +
     * nonce. Other
     * families: the whole payload. The branch follows the network, not the
     * fields present: schemes ignore fields they do not read, so a NEAR
     * payment carrying an `authorization` would otherwise get a fresh key.
     */
    public static function key(array $payment): string
    {
        $network = (string) ($payment['accepted']['network'] ?? '');
        $family = strstr($network, ':', true);
        $auth = 'eip155' === $family ? ($payment['payload']['authorization'] ?? null) : null;
        $wire = 'solana' === $family && is_string($payment['payload']['transaction'] ?? null) ? base64_decode($payment['payload']['transaction'], true) : false;
        $solana = is_string($wire) ? Solana::parse($wire) : null;
        $near = 'near' === $family && is_string($payment['payload']['signedDelegateAction'] ?? null) ? base64_decode($payment['payload']['signedDelegateAction'], true) : false;
        $near = is_string($near) ? NearExact::decodeSignedDelegateAction($near) : null;
        $id = match (true) {
            is_array($auth) => [$payment['accepted']['network'] ?? '', strtolower((string) ($auth['from'] ?? '')), strtolower((string) ($auth['nonce'] ?? ''))],
            // The signed message, not the signatures: the fee payer's slot is
            // empty now and filled later, and any bytes there are the same payment.
            null !== $solana => [$payment['accepted']['network'] ?? '', bin2hex($solana['message'])],
            // What NEAR spends once: the access key's nonce.
            null !== $near => [$payment['accepted']['network'] ?? '', $near['sender_id'], bin2hex($near['public_key_bytes']), $near['nonce']],
            default => $payment['payload'] ?? null,
        };

        return hash('sha256', json_encode($id, JSON_THROW_ON_ERROR));
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
     * A receipt; success only when the facilitator said `success: true`.
     *
     * @param \Closure $settle fn(array $payment, array $req): array
     */
    public static function run(\Closure $settle, array $payment, array $req): array
    {
        try {
            $receipt = $settle($payment, $req);
        } catch (FacilitatorException) {
            // Not the buyer's fault: the HTTP transport answers 502. It may have sent the
            // transaction before going silent, so the claim is kept.
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
