<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * A spending cap per network, in the token's atomic units.
 *
 * ponytail: in memory, so it lasts one process (one agent run); persist the
 * spent totals if a cap must hold across runs.
 */
final class Budget {

  /**
   * The longest a signed payment may stay valid, whatever the seller asks.
   */
  public const int MAX_TIMEOUT = 600;

  /**
   * Atomic units spent so far, by network.
   */
  private array $spent = [];

  /**
   * @param array<string, string> $caps
   *   Network => maximum atomic units, e.g. ['eip155:84532' => '50000'] for
   *   0.05 USDC. Networks without a cap get nothing.
   */
  public function __construct(private readonly array $caps) {}

  /**
   * A cap in dollars on one network, converted with the token's decimals.
   */
  public static function usd(string $network, string $usd): self {
    return new self([$network => Networks::atomic($usd, Networks::get($network)['decimals'])]);
  }

  /**
   * Whether to sign this offer at all. The seller wrote it, so nothing in
   * it is trusted: only known tokens, a sane amount and a short validity.
   */
  public function allows(array $req): bool {
    $network = $req['network'] ?? NULL;
    $cap = is_string($network) ? $this->caps[$network] ?? NULL : NULL;
    if ($cap === NULL || !is_string($req['amount'] ?? NULL) || !preg_match('/^[1-9][0-9]{0,77}$/', $req['amount'])) {
      return FALSE;
    }
    // Canonical only: Eip712 pads and strips 0x, so "0330…" or "0x000330…"
    // would sign to the same address that screening was shown in another form.
    if (!is_string($req['payTo'] ?? NULL) || !preg_match('/^0x[0-9a-fA-F]{40}$/', $req['payTo'])) {
      return FALSE;
    }
    $timeout = $req['maxTimeoutSeconds'] ?? NULL;
    if (!is_int($timeout) || $timeout < 1 || $timeout > self::MAX_TIMEOUT) {
      return FALSE;
    }
    // The cap is in this token's units: any other asset or EIP-712 domain
    // would make it meaningless (or sign for a token we did not mean).
    try {
      $token = Networks::get($network);
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
    if (strcasecmp((string) ($req['asset'] ?? ''), $token['asset']) !== 0 || ($req['extra']['name'] ?? NULL) !== $token['extra']['name'] || ($req['extra']['version'] ?? NULL) !== $token['extra']['version']) {
      return FALSE;
    }
    return gmp_cmp(gmp_add($this->spent[$network] ?? 0, $req['amount']), $cap) <= 0;
  }

  public function spend(array $req): void {
    $this->spent[$req['network']] = gmp_strval(gmp_add($this->spent[$req['network']] ?? 0, (string) $req['amount']));
  }

  public function spent(string $network): string {
    return $this->spent[$network] ?? '0';
  }

}
