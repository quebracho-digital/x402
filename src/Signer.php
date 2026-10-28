<?php

declare(strict_types=1);

namespace Quebracho\X402;

use kornrunner\Secp256k1;
use Mdanter\Ecc\Curves\CurveFactory;
use Mdanter\Ecc\Curves\SecgCurve;

/**
 * An EVM key: its address, and signatures over 32-byte digests.
 *
 * Signatures are deterministic (RFC 6979) and low-s, so they equal viem's.
 */
final class Signer {

  /**
   * The private key, as raw 32 bytes.
   */
  private readonly string $key;

  public function __construct(#[\SensitiveParameter] string $privateKey) {
    if (!preg_match('/^0x[0-9a-fA-F]{64}$/', $privateKey)) {
      throw new \InvalidArgumentException('A private key is 0x followed by 64 hex characters.');
    }
    $this->key = strtolower(substr($privateKey, 2));
  }

  /**
   * The EIP-55 checksummed address.
   */
  public function address(): string {
    $point = CurveFactory::getGeneratorByName(SecgCurve::NAME_SECP_256K1)
      ->getPrivateKeyFrom(gmp_init($this->key, 16))
      ->getPublicKey()->getPoint();
    $pub = str_pad(gmp_export($point->getX()), 32, "\0", STR_PAD_LEFT) . str_pad(gmp_export($point->getY()), 32, "\0", STR_PAD_LEFT);
    return self::checksum(substr(Eip712::keccak($pub), -40));
  }

  /**
   * Signs a 0x-hex digest; returns r ‖ s ‖ v (v = 27 or 28) as 0x-hex.
   */
  public function sign(string $digest): string {
    $sig = (new Secp256k1())->sign(substr($digest, 2), $this->key);
    $hex = fn(\GMP $n) => str_pad(gmp_strval($n, 16), 64, '0', STR_PAD_LEFT);
    return '0x' . $hex($sig->getR()) . $hex($sig->getS()) . dechex(27 + $sig->getRecoveryParam());
  }

  /**
   * EIP-55: uppercase each hex letter whose nibble in keccak(address) >= 8.
   */
  public static function checksum(string $address): string {
    $address = strtolower(str_starts_with($address, '0x') ? substr($address, 2) : $address);
    $hash = substr(Eip712::keccak($address), 2);
    $out = '';
    foreach (str_split($address) as $i => $c) {
      $out .= hexdec($hash[$i]) >= 8 ? strtoupper($c) : $c;
    }
    return '0x' . $out;
  }

  /**
   * Shows the address only: never the private key.
   */
  public function __debugInfo(): array {
    return ['address' => $this->address()];
  }

}
