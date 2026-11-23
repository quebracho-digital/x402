<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The protobuf wire format, only the parts Concordium's node API needs:
 * varints, length-delimited fields and repeated message entries (maps).
 *
 * @internal
 */
final class Proto
{
    public static function varint(int $value): string
    {
        if ($value < 0) {
            throw new \InvalidArgumentException('A protobuf varint is not negative.');
        }
        $out = '';
        do {
            $byte = $value & 0x7F;
            $value >>= 7;
            $out .= \chr($byte | ($value > 0 ? 0x80 : 0x00));
        } while ($value > 0);

        return $out;
    }

    public static function uint(int $field, int $value): string
    {
        return self::varint(($field << 3) | 0).self::varint($value);
    }

    public static function bytes(int $field, string $value): string
    {
        return self::varint(($field << 3) | 2).self::varint(strlen($value)).$value;
    }

    public static function message(int $field, string $value): string
    {
        return self::bytes($field, $value);
    }

    /**
     * One map entry: its key as field 1 (a varint) and its value as field 2 (a message).
     */
    public static function entry(int $key, string $value): string
    {
        return self::uint(1, $key).self::message(2, $value);
    }

    /**
     * A message as field => its values, in the order they came.
     *
     * @return array<int, list<array{wire: int, value: int|string}>>
     */
    public static function parse(string $bytes): array
    {
        $fields = [];
        $i = 0;
        $end = strlen($bytes);
        while ($i < $end) {
            $key = self::readVarint($bytes, $i);
            $field = $key >> 3;
            $wire = $key & 0x07;
            if ($field < 1) {
                throw new \InvalidArgumentException('A protobuf field number is positive.');
            }
            $value = match ($wire) {
                0 => self::readVarint($bytes, $i),
                1 => self::readFixed($bytes, $i, 8),
                2 => self::readBytes($bytes, $i),
                5 => self::readFixed($bytes, $i, 4),
                default => throw new \InvalidArgumentException("Unsupported protobuf wire type: $wire."),
            };
            $fields[$field][] = ['wire' => $wire, 'value' => $value];
        }

        return $fields;
    }

    /**
     * The first value of a field, or NULL when it is absent.
     */
    public static function first(array $fields, int $field): int|string|null
    {
        return $fields[$field][0]['value'] ?? null;
    }

    /**
     * The first value of a field read as an embedded message.
     *
     * @return array<int, list<array{wire: int, value: int|string}>>
     */
    public static function nested(array $fields, int $field): array
    {
        $value = self::first($fields, $field);

        return is_string($value) ? self::parse($value) : [];
    }

    /**
     * The entries of a map field as key => the first value of its embedded message.
     *
     * @return array<int, array<int, list<array{wire: int, value: int|string}>>>
     */
    public static function map(array $fields, int $field): array
    {
        $out = [];
        foreach ($fields[$field] ?? [] as $entry) {
            if (!is_string($entry['value'])) {
                continue;
            }
            $parsed = self::parse($entry['value']);
            $key = self::first($parsed, 1);
            $value = self::first($parsed, 2);
            // protobuf omits a map key that is the default value (0), as the chain does for credential 0.
            $key ??= 0;
            if (is_int($key) && is_string($value)) {
                $out[$key] = self::parse($value);
            }
        }

        return $out;
    }

    private static function readVarint(string $bytes, int &$i): int
    {
        $value = 0;
        $shift = 0;
        $end = strlen($bytes);
        while ($i < $end) {
            $byte = \ord($bytes[$i++]);
            $value |= ($byte & 0x7F) << $shift;
            if (0 === ($byte & 0x80)) {
                return $value;
            }
            $shift += 7;
            if ($shift > 63) {
                throw new \InvalidArgumentException('A protobuf varint is at most 10 bytes.');
            }
        }

        throw new \InvalidArgumentException('Truncated protobuf varint.');
    }

    private static function readBytes(string $bytes, int &$i): string
    {
        $length = self::readVarint($bytes, $i);
        if ($i + $length > strlen($bytes)) {
            throw new \InvalidArgumentException('Truncated protobuf bytes.');
        }
        $value = substr($bytes, $i, $length);
        $i += $length;

        return $value;
    }

    private static function readFixed(string $bytes, int &$i, int $length): string
    {
        if ($i + $length > strlen($bytes)) {
            throw new \InvalidArgumentException('Truncated protobuf fixed field.');
        }
        $value = substr($bytes, $i, $length);
        $i += $length;

        return $value;
    }
}
