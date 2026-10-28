<?php

declare(strict_types=1);

namespace Quebracho\X402;

use kornrunner\Keccak;

/**
 * EIP-712 typed-data hashing: what both the buyer and the seller sign.
 *
 * Covers the types x402 and Circle use: string, bytes, bytesN, address,
 * bool, (u)intN and nested structs. Arrays are not supported.
 */
final class Eip712
{
    private const DOMAIN_FIELDS = [
        'name' => 'string',
        'version' => 'string',
        'chainId' => 'uint256',
        'verifyingContract' => 'address',
        'salt' => 'bytes32',
    ];

    /**
     * Keccak-256 of raw bytes, as 0x-prefixed hex.
     */
    public static function keccak(string $bytes): string
    {
        return '0x'.Keccak::hash($bytes, 256);
    }

    /**
     * The digest to sign: keccak(0x1901 ‖ domainSeparator ‖ hashStruct(message)).
     *
     * @param array  $domain
     *                            Any of name, version, chainId, verifyingContract, salt
     * @param array  $types
     *                            Struct name => list of ['name' => …, 'type' => …], as in viem/ethers
     * @param string $primaryType
     *                            The struct the message is
     * @param array  $message
     *                            Field name => value
     */
    public static function hash(array $domain, array $types, string $primaryType, array $message): string
    {
        $types['EIP712Domain'] = [];
        foreach (self::DOMAIN_FIELDS as $name => $type) {
            if (array_key_exists($name, $domain)) {
                $types['EIP712Domain'][] = ['name' => $name, 'type' => $type];
            }
        }

        return self::keccak("\x19\x01".self::hashStruct('EIP712Domain', $domain, $types).self::hashStruct($primaryType, $message, $types));
    }

    /**
     * The hashStruct of a value, as raw 32 bytes.
     */
    private static function hashStruct(string $type, array $data, array $types): string
    {
        $encoded = self::raw(self::keccak(self::encodeType($type, $types)));
        foreach ($types[$type] as $field) {
            if (!array_key_exists($field['name'], $data)) {
                throw new \InvalidArgumentException("Missing field {$type}.{$field['name']}.");
            }
            $encoded .= self::encodeValue($field['type'], $data[$field['name']], $types);
        }

        return self::raw(self::keccak($encoded));
    }

    /**
     * The type string: "Primary(fields)", then every referenced struct by name.
     */
    private static function encodeType(string $primary, array $types): string
    {
        $deps = self::dependencies($primary, $types);
        unset($deps[$primary]);
        ksort($deps);
        $out = '';
        foreach (array_merge([$primary], array_keys($deps)) as $name) {
            $out .= $name.'('.implode(',', array_map(fn ($f) => "{$f['type']} {$f['name']}", $types[$name])).')';
        }

        return $out;
    }

    /**
     * Every struct type that a type references, recursively.
     */
    private static function dependencies(string $type, array $types, array $found = []): array
    {
        if (isset($found[$type]) || !isset($types[$type])) {
            return $found;
        }
        $found[$type] = true;
        foreach ($types[$type] as $field) {
            $found = self::dependencies($field['type'], $types, $found);
        }

        return $found;
    }

    /**
     * One field, as 32 raw bytes.
     */
    private static function encodeValue(string $type, mixed $value, array $types): string
    {
        if (isset($types[$type])) {
            return self::hashStruct($type, $value, $types);
        }
        if (str_ends_with($type, ']')) {
            throw new \InvalidArgumentException("Array types are not supported: $type.");
        }

        return match (true) {
            'string' === $type => self::raw(self::keccak((string) $value)),
            'bytes' === $type => self::raw(self::keccak(self::raw($value))),
            'bool' === $type => self::word(gmp_init($value ? 1 : 0)),
            'address' === $type => str_pad(self::raw($value), 32, "\0", STR_PAD_LEFT),
            (bool) preg_match('/^bytes(\d+)$/', $type, $m) => str_pad(self::raw($value), 32, "\0", STR_PAD_RIGHT),
            (bool) preg_match('/^u?int\d*$/', $type) => self::word(self::number($value)),
            default => throw new \InvalidArgumentException("Unsupported type: $type."),
        };
    }

    /**
     * An integer given as decimal or 0x-prefixed hex.
     */
    private static function number(mixed $value): \GMP
    {
        $value = (string) $value;

        return str_starts_with($value, '0x') ? gmp_init(substr($value, 2), 16) : gmp_init($value, 10);
    }

    /**
     * A non-negative integer as a 32-byte big-endian word.
     */
    private static function word(\GMP $n): string
    {
        if (gmp_sign($n) < 0) {
            // ponytail: negative intN unused by x402; add two's complement if needed.
            throw new \InvalidArgumentException('Negative integers are not supported.');
        }

        return str_pad(gmp_export($n), 32, "\0", STR_PAD_LEFT);
    }

    /**
     * Converts 0x-hex to raw bytes.
     */
    public static function raw(string $hex): string
    {
        $hex = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;
        $raw = hex2bin(strlen($hex) % 2 ? "0$hex" : $hex);
        if (false === $raw) {
            throw new \InvalidArgumentException('Not hex.');
        }

        return $raw;
    }
}
