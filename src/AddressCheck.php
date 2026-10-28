<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * A PSR-14 event: may this address take part in this payment?
 *
 * The seller asks it about the payer once the signature proved who that is,
 * before anything is claimed or run. The buyer asks it about `payTo` before
 * signing. Any listener (a sanctions list, an allowlist, an API) may reject;
 * the first rejection stops the others. Without a dispatcher nothing is asked.
 */
final class AddressCheck implements StoppableEventInterface {

  public const string PAYER = 'payer';
  public const string PAY_TO = 'payTo';

  private ?string $reason = NULL;

  /**
   * @param string $role
   *   self::PAYER (seller side) or self::PAY_TO (buyer side).
   * @param string $address
   *   In the chain's own form: 0x… on EVM, an account ID on NEAR.
   * @param array $req
   *   The payment requirement this address is part of.
   */
  public function __construct(
    public readonly string $role,
    public readonly string $network,
    public readonly string $address,
    public readonly array $req,
  ) {}

  public function reject(string $reason): void {
    $this->reason = $reason;
  }

  public function reason(): ?string {
    return $this->reason;
  }

  public function isPropagationStopped(): bool {
    return $this->reason !== NULL;
  }

  /**
   * Why this address may not take part, or NULL when it may.
   *
   * Fails closed: an address it cannot read, or a listener that throws,
   * is a rejection, not a pass.
   */
  public static function run(?EventDispatcherInterface $events, string $role, ?string $address, array $req): ?string {
    if ($events === NULL) {
      return NULL;
    }
    if ($address === NULL || $address === '') {
      return 'invalid_payload';
    }
    try {
      return $events->dispatch(new self($role, (string) $req['network'], $address, $req))->reason();
    }
    catch (\Throwable) {
      return 'address_check_failed';
    }
  }

}
