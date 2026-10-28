<?php

namespace Quebracho\X402;

/**
 * A spending cap per network, in the token's atomic units.
 *
 * ponytail: in memory, so it lasts one process (one agent run); persist the
 * spent totals if a cap must hold across runs.
 */
final class Budget {

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

  public function allows(array $req): bool {
    $cap = $this->caps[$req['network']] ?? NULL;
    return $cap !== NULL && gmp_cmp(gmp_add($this->spent[$req['network']] ?? 0, (string) $req['amount']), $cap) <= 0;
  }

  public function spend(array $req): void {
    $this->spent[$req['network']] = gmp_strval(gmp_add($this->spent[$req['network']] ?? 0, (string) $req['amount']));
  }

  public function spent(string $network): string {
    return $this->spent[$network] ?? '0';
  }

}
