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
      // Typed before use: an array here would reach the EIP-712 encoder.
      count(array_filter(['from', 'to', 'value', 'validAfter', 'validBefore', 'nonce'], fn($k) => !is_string($auth[$k] ?? NULL))) > 0 => 'invalid_payload',
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

  private function now(): int {
    return $this->clock ? ($this->clock)() : time();
  }

}
