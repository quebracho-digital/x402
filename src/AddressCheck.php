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
 *
 * A slow listener (an API) defers instead: it starts its lookup and hands
 * over a closure with the answer. The seller reads it just before settling,
 * so the lookup runs while the tool does; the buyer reads it before signing.
 */
final class AddressCheck implements StoppableEventInterface {

  public const string PAYER = 'payer';
  public const string PAY_TO = 'payTo';

  private ?string $reason = NULL;

  /**
   * @var \Closure[]
   */
  private array $later = [];

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

  /**
   * @param \Closure $later
   *   fn(): ?string — the reason to reject, or NULL; it may block until the
   *   lookup answers. Throwing is a rejection.
   */
  public function defer(\Closure $later): void {
    $this->later[] = $later;
  }

  /**
   * The rejection known now, without waiting for deferred lookups.
   */
  public function reason(): ?string {
    return $this->reason;
  }

  /**
   * The final rejection, or NULL: waits for every deferred lookup.
   */
  public function verdict(): ?string {
    foreach ($this->later as $later) {
      if ($this->reason !== NULL) {
        break;
      }
      try {
        $this->reason = $later();
      }
      catch (\Throwable) {
        $this->reason = 'address_check_failed';
      }
    }
    $this->later = [];
    return $this->reason;
  }

  public function isPropagationStopped(): bool {
    return $this->reason !== NULL;
  }

  /**
   * Dispatches the check, or NULL without a dispatcher.
   *
   * Fails closed: an address it cannot read, or a listener that throws,
   * is a rejection, not a pass.
   */
  public static function start(?EventDispatcherInterface $events, string $role, ?string $address, array $req): ?self {
    if ($events === NULL) {
      return NULL;
    }
    $check = new self($role, (string) $req['network'], (string) $address, $req);
    if ($address === NULL || $address === '') {
      $check->reject('invalid_payload');
      return $check;
    }
    try {
      $events->dispatch($check);
    }
    catch (\Throwable) {
      $check->reject('address_check_failed');
    }
    return $check;
  }

  /**
   * Why this address may not take part, or NULL when it may; waits for
   * deferred lookups.
   */
  public static function run(?EventDispatcherInterface $events, string $role, ?string $address, array $req): ?string {
    return self::start($events, $role, $address, $req)?->verdict();
  }

}
