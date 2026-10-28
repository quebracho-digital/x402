<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Claims each payment once, durably and atomically, on one host.
 *
 * fopen 'x' is O_CREAT|O_EXCL: of N concurrent claims exactly one wins,
 * across processes and restarts.
 *
 * ponytail: one file per payment, never pruned; bounded by validBefore
 * caps. Swap for a shared store (DB unique key, Redis SET NX) on several hosts.
 */
final class FileClaims {

  public function __construct(private readonly string $dir) {}

  public function __invoke(string $key): bool {
    if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, TRUE) && !is_dir($this->dir)) {
      // Cannot record it, so cannot prove it is fresh: refuse.
      return FALSE;
    }
    $handle = @fopen($this->dir . '/' . $key, 'x');
    if ($handle === FALSE) {
      return FALSE;
    }
    fclose($handle);
    return TRUE;
  }

}
