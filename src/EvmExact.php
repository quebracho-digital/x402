<?php

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
    return match (TRUE) {
      !is_array($auth) || !is_string($signature) => 'invalid_payload',
      ($accepted['scheme'] ?? NULL) !== 'exact' || ($accepted['network'] ?? NULL) !== $req['network'] => 'unsupported_scheme',
      strcasecmp((string) ($accepted['asset'] ?? ''), $req['asset']) !== 0 => 'invalid_asset',
      strcasecmp((string) ($auth['to'] ?? ''), $req['payTo']) !== 0 => 'invalid_recipient',
      (string) ($auth['value'] ?? '') !== (string) $req['amount'] => 'invalid_amount',
      (int) ($auth['validAfter'] ?? PHP_INT_MAX) > $now => 'not_yet_valid',
      (int) ($auth['validBefore'] ?? 0) <= $now => 'expired',
      strcasecmp((string) Signer::recover(Authorization::digest($req, $auth), $signature), (string) ($auth['from'] ?? '')) !== 0 => 'invalid_signature',
      default => NULL,
    };
  }

  private function now(): int {
    return $this->clock ? ($this->clock)() : time();
  }

}
