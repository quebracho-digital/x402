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
final class Signer
{
    /**
     * The private key, as raw 32 bytes.
     */
    private readonly string $key;

    public function __construct(#[\SensitiveParameter] string $privateKey)
    {
        if (!preg_match('/^0x[0-9a-fA-F]{64}$/', $privateKey)) {
            throw new \InvalidArgumentException('A private key is 0x followed by 64 hex characters.');
        }
        $this->key = strtolower(substr($privateKey, 2));
    }

    /**
     * The EIP-55 checksummed address.
     */
    public function address(): string
    {
        $point = CurveFactory::getGeneratorByName(SecgCurve::NAME_SECP_256K1)
          ->getPrivateKeyFrom(gmp_init($this->key, 16))
          ->getPublicKey()->getPoint();

        return self::addressOf($point->getX(), $point->getY());
    }

    /**
     * The address that signed a 0x-hex digest (ecrecover), or NULL when the
     * signature is malformed or recovers nothing.
     */
    public static function recover(string $digest, string $signature): ?string
    {
        if (!preg_match('/^0x([0-9a-fA-F]{64})([0-9a-fA-F]{64})([0-9a-fA-F]{2})$/', $signature, $m)) {
            return null;
        }
        $generator = CurveFactory::getGeneratorByName(SecgCurve::NAME_SECP_256K1);
        $curve = $generator->getCurve();
        $n = $generator->getOrder();
        $p = $curve->getPrime();
        [$r, $s, $v] = [gmp_init($m[1], 16), gmp_init($m[2], 16), hexdec($m[3]) - 27];
        if (!in_array($v, [0, 1], true) || gmp_sign($r) <= 0 || gmp_cmp($r, $n) >= 0 || gmp_sign($s) <= 0 || gmp_cmp($s, gmp_div_q($n, 2)) > 0) {
            return null;
        }
        // Low-s only (EIP-2): (r, n−s) is the same signature, malleated.
        // R = (r, y) on y² = x³ + 7, with y's parity from v. p ≡ 3 mod 4.
        $y = gmp_powm(gmp_mod(gmp_add(gmp_powm($r, 3, $p), 7), $p), gmp_div_q(gmp_add($p, 1), 4), $p);
        if (0 !== gmp_cmp(gmp_powm($y, 2, $p), gmp_mod(gmp_add(gmp_powm($r, 3, $p), 7), $p))) {
            return null;
        }
        if (gmp_intval(gmp_mod($y, 2)) !== $v) {
            $y = gmp_sub($p, $y);
        }
        // Q = r⁻¹·(s·R − e·G).
        $rInv = gmp_invert($r, $n);
        $e = gmp_init(substr($digest, 2), 16);
        $q = $curve->getPoint($r, $y, $n)->mul(gmp_mod(gmp_mul($s, $rInv), $n))
          ->add($generator->mul(gmp_mod(gmp_neg(gmp_mul($e, $rInv)), $n)));

        return $q->isInfinity() ? null : self::addressOf($q->getX(), $q->getY());
    }

    private static function addressOf(\GMP $x, \GMP $y): string
    {
        $pub = str_pad(gmp_export($x), 32, "\0", STR_PAD_LEFT).str_pad(gmp_export($y), 32, "\0", STR_PAD_LEFT);

        return self::checksum(substr(Eip712::keccak($pub), -40));
    }

    /**
     * Signs a 0x-hex digest; returns r ‖ s ‖ v (v = 27 or 28) as 0x-hex.
     */
    public function sign(string $digest): string
    {
        $sig = (new Secp256k1())->sign(substr($digest, 2), $this->key);
        $hex = fn (\GMP $n) => str_pad(gmp_strval($n, 16), 64, '0', STR_PAD_LEFT);

        return '0x'.$hex($sig->getR()).$hex($sig->getS()).dechex(27 + $sig->getRecoveryParam());
    }

    /**
     * EIP-55: uppercase each hex letter whose nibble in keccak(address) >= 8.
     */
    public static function checksum(string $address): string
    {
        $address = strtolower(str_starts_with($address, '0x') ? substr($address, 2) : $address);
        $hash = substr(Eip712::keccak($address), 2);
        $out = '';
        foreach (str_split($address) as $i => $c) {
            $out .= hexdec($hash[$i]) >= 8 ? strtoupper($c) : $c;
        }

        return '0x'.$out;
    }

    /**
     * Shows the address only: never the private key.
     */
    public function __debugInfo(): array
    {
        return ['address' => $this->address()];
    }
}
