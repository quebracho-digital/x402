<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * What both middlewares do around the handler: claim a payment once, and
 * read a facilitator's answer without trusting its shape.
 */
final class Settlement {

  /**
   * The payment's identity: what the chain spends once, not its signature.
   *
   * EVM: network + payer + EIP-3009 nonce (re-signing the same nonce must
   * not buy a second delivery). Other families: the whole payload.
   */
  public static function key(array $payment): string {
    $auth = $payment['payload']['authorization'] ?? NULL;
    $id = is_array($auth)
      ? [$payment['accepted']['network'] ?? '', strtolower((string) ($auth['from'] ?? '')), (string) ($auth['nonce'] ?? '')]
      : $payment['payload'] ?? NULL;
    return hash('sha256', json_encode($id, JSON_THROW_ON_ERROR));
  }

  /**
   * A receipt; success only when the facilitator said `success: true`.
   *
   * @param ?\Closure $settle
   *   NULL is mock mode: a receipt without a transaction, nothing moved.
   */
  public static function run(?\Closure $settle, array $payment, array $req): array {
    if ($settle === NULL) {
      return ['success' => TRUE, 'transaction' => '', 'network' => $req['network'], 'payer' => $payment['payload']['authorization']['from'] ?? NULL];
    }
    try {
      $receipt = $settle($payment, $req);
    }
    catch (\Throwable) {
      $receipt = NULL;
    }
    if (is_array($receipt) && ($receipt['success'] ?? NULL) === TRUE) {
      return $receipt;
    }
    $reason = is_array($receipt) && is_string($receipt['errorReason'] ?? NULL) ? $receipt['errorReason'] : 'unexpected_settle_error';
    return ['success' => FALSE, 'errorReason' => $reason, 'transaction' => '', 'network' => $req['network']];
  }

}
