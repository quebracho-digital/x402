<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The `exact` scheme on EVM chains: an EIP-3009 TransferWithAuthorization.
 */
final class EvmExact implements Scheme {

  /**
   * @param ?Signer $buyer
   *   Needed to pay; a seller that only verifies passes none.
   * @param ?\Closure $clock
   *   Returns the current Unix time; tests pin it.
   */
  public function __construct(
    private readonly ?Signer $buyer = NULL,
    private readonly ?\Closure $clock = NULL,
  ) {}

  public function supports(string $network): bool {
    return str_starts_with($network, 'eip155:');
  }

  public function pay(array $required, array $req): array {
    $buyer = $this->buyer ?? throw new \LogicException('Paying needs a buyer key.');
    return Authorization::pay($buyer, $required, $req, $this->now());
  }

  public function verify(array $payment, array $req): ?string {
    $auth = $payment['payload']['authorization'] ?? NULL;
    $signature = $payment['payload']['signature'] ?? NULL;
    $accepted = $payment['accepted'] ?? [];
    $now = $this->now();
    // Reason codes from the x402 v2 spec §9; clients match on them.
    return match (TRUE) {
      !is_array($auth) || !is_string($signature) || !is_array($accepted) => 'invalid_payload',
      // One spelling per value: the encoder reads "0xAB", "0xab" and "ab" as
      // the same bytes, so a looser format would let one signature claim
      // several deliveries (Settlement::key) or reach the encoder malformed.
      !self::canonical($auth, $signature) => 'invalid_payload',
      ($payment['x402Version'] ?? NULL) !== 2 => 'invalid_x402_version',
      ($accepted['scheme'] ?? NULL) !== 'exact' => 'invalid_scheme',
      ($accepted['network'] ?? NULL) !== $req['network'] => 'invalid_network',
      empty($req['extra']['name']) || empty($req['extra']['version']) => 'invalid_payment_requirements',
      // The buyer echoes the offer it took; a different echo is not that offer.
      strcasecmp((string) ($accepted['asset'] ?? ''), $req['asset']) !== 0
        || strcasecmp((string) ($accepted['payTo'] ?? ''), $req['payTo']) !== 0
        || (string) ($accepted['amount'] ?? '') !== (string) $req['amount'] => 'invalid_payload',
      strcasecmp((string) ($auth['to'] ?? ''), $req['payTo']) !== 0 => 'invalid_exact_evm_payload_recipient_mismatch',
      (string) ($auth['value'] ?? '') !== (string) $req['amount'] => 'invalid_exact_evm_payload_authorization_value_mismatch',
      (int) ($auth['validAfter'] ?? PHP_INT_MAX) > $now => 'invalid_exact_evm_payload_authorization_valid_after',
      // 6 s of slack so it does not expire on its way into a block, as the reference facilitator does.
      (int) ($auth['validBefore'] ?? 0) < $now + 6 => 'invalid_exact_evm_payload_authorization_valid_before',
      // No longer than the offer allows (+60 s clock skew): bounds replay windows and claim storage.
      (int) $auth['validBefore'] > $now + (int) ($req['maxTimeoutSeconds'] ?? 0) + 60 => 'invalid_exact_evm_payload_authorization_valid_before',
      strcasecmp((string) Signer::recover(Authorization::digest($req, $auth), $signature), (string) ($auth['from'] ?? '')) !== 0 => 'invalid_exact_evm_payload_signature',
      default => NULL,
    };
  }

  private static function canonical(array $auth, string $signature): bool {
    $formats = ['from' => '/^0x[0-9a-fA-F]{40}$/', 'to' => '/^0x[0-9a-fA-F]{40}$/', 'nonce' => '/^0x[0-9a-fA-F]{64}$/', 'value' => '/^(0|[1-9][0-9]{0,77})$/', 'validAfter' => '/^(0|[1-9][0-9]{0,18})$/', 'validBefore' => '/^(0|[1-9][0-9]{0,18})$/'];
    foreach ($formats as $key => $format) {
      if (!is_string($auth[$key] ?? NULL) || !preg_match($format, $auth[$key])) {
        return FALSE;
      }
    }
    return (bool) preg_match('/^0x[0-9a-fA-F]{130}$/', $signature);
  }

  public function payer(array $payment): ?string {
    $from = $payment['payload']['authorization']['from'] ?? NULL;
    return is_string($from) ? $from : NULL;
  }

  private function now(): int {
    return $this->clock ? ($this->clock)() : time();
  }

}
