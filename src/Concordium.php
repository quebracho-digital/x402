<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Concordium's wire bits that a payment needs, offline: account addresses and
 * the digest a V1 sponsored transfer is signed over.
 *
 * Ported from the Kalibang spike, whose digest the chain finalized on testnet
 * (2026-10-21). The node's own sign-hash call is unimplemented on public
 * nodes, so the digest is ours, as in the JS SDK's AccountTransactionV1.
 */
final class Concordium
{
    private const string ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /** Energy of a CCD transfer, before signatures and size (the JSON's executionEnergyAmount). */
    public const int TRANSFER_ENERGY = 300;

    /**
     * An account address (base58check, version byte 1) as its 32 raw bytes.
     *
     * @throws InvalidPaymentException
     */
    public static function decodeAddress(string $address): string
    {
        if ('' === $address || strlen($address) > 51) {
            throw new InvalidPaymentException("Not a Concordium account address: $address");
        }
        $n = gmp_init(0);
        foreach (str_split($address) as $char) {
            $digit = strpos(self::ALPHABET, $char);
            if (false === $digit) {
                throw new InvalidPaymentException("Not base58: $address");
            }
            $n = gmp_add(gmp_mul($n, 58), $digit);
        }
        $bytes = str_pad(gmp_export($n), 37, "\0", \STR_PAD_LEFT);
        if (37 !== strlen($bytes) || "\x01" !== $bytes[0] || substr(hash('sha256', hash('sha256', substr($bytes, 0, 33), true), true), 0, 4) !== substr($bytes, 33)) {
            throw new InvalidPaymentException("Not a Concordium account address: $address");
        }
        if (self::encodeAddress(substr($bytes, 1, 32)) !== $address) {
            throw new InvalidPaymentException("Not the canonical Concordium account address: $address");
        }

        return substr($bytes, 1, 32);
    }

    public static function encodeAddress(string $raw): string
    {
        $payload = "\x01".$raw;
        $n = gmp_import($payload.substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4));
        $out = '';
        while (gmp_sign($n) > 0) {
            [$n, $r] = gmp_div_qr($n, 58);
            $out = self::ALPHABET[gmp_intval($r)].$out;
        }

        return $out;
    }

    /**
     * Whether $address is a Concordium account address in its one spelling.
     */
    public static function isAddress(string $address): bool
    {
        try {
            self::decodeAddress($address);

            return true;
        } catch (InvalidPaymentException) {
            return false;
        }
    }

    /**
     * Energy the whole transaction costs: the base, 100 per signature and one
     * per byte of header and payload. The sponsor pays for all of it.
     */
    public static function energy(int $base, int $signatures, int $headerAndPayloadBytes): int
    {
        return $base + 100 * $signatures + $headerAndPayloadBytes;
    }

    /**
     * The digest sender and sponsor both sign, of an SDK-JSON V1 transfer.
     *
     * @param array $tx `signedTransaction` of the payload; only the fields below are read
     *
     * @throws InvalidPaymentException Not a CCD transfer, or a field that is not what it should be
     */
    public static function signHash(array $tx): string
    {
        $parts = self::parts($tx);

        return hash('sha256', str_repeat("\0", 31)."\x01".$parts['header'].$parts['body'], true);
    }

    /**
     * The energy the digest commits to: the base, 100 per signature and one
     * per byte of header and payload.
     */
    public static function totalEnergy(array $tx): int
    {
        return self::parts($tx)['energy'];
    }

    /**
     * The bytes and the numbers a V1 transfer commits to, shared by the digest,
     * the sponsor's protobuf and the checks.
     *
     * @return array{sender: string, to: string, sponsor: string, nonce: int, expiry: int, amount: int, energy: int, header: string, body: string}
     *
     * @throws InvalidPaymentException
     */
    public static function parts(array $tx): array
    {
        if (array_diff(array_keys($tx), ['version', 'header', 'payload', 'signatures'])) {
            throw new InvalidPaymentException('The transaction has fields the scheme does not read.');
        }
        $h = $tx['header'] ?? [];
        $p = $tx['payload'] ?? [];
        if (!is_array($h) || !is_array($p) || 'transfer' !== ($p['type'] ?? null)) {
            throw new InvalidPaymentException('Only a plain CCD transfer is supported.');
        }
        $payloadKeys = array_keys($p);
        sort($payloadKeys);
        if (array_diff(array_keys($h), ['sender', 'nonce', 'expiry', 'executionEnergyAmount', 'numSignatures', 'sponsor']) || ['amount', 'toAddress', 'type'] !== $payloadKeys) {
            throw new InvalidPaymentException('The transfer has fields the scheme does not read.');
        }
        $sponsorField = $h['sponsor'] ?? [];
        if (!is_array($sponsorField) || array_diff(array_keys($sponsorField), ['address', 'account', 'numSignatures'])
            || array_key_exists('address', $sponsorField) === array_key_exists('account', $sponsorField)) {
            throw new InvalidPaymentException('The sponsor is one account address, in one spelling.');
        }
        $senderAddress = $h['sender'] ?? null;
        $toAddress = $p['toAddress'] ?? null;
        $sponsorAddress = $sponsorField['address'] ?? $sponsorField['account'];
        if (!is_string($senderAddress) || !is_string($toAddress) || !is_string($sponsorAddress)) {
            throw new InvalidPaymentException('The addresses are base58 strings.');
        }
        $sender = self::decodeAddress($senderAddress);
        $to = self::decodeAddress($toAddress);
        $sponsor = self::decodeAddress($sponsorAddress);
        $signatures = $h['numSignatures'] ?? null;
        $sponsorSignatures = $sponsorField['numSignatures'] ?? null;
        if (!is_int($signatures) || $signatures < 1 || !is_int($sponsorSignatures) || $sponsorSignatures < 0) {
            throw new InvalidPaymentException('The signature counts are not plain integers.');
        }
        $base = $h['executionEnergyAmount'] ?? 0;
        if (!is_int($base) || $base < 0) {
            throw new InvalidPaymentException('The base energy is not a plain integer.');
        }
        foreach (['nonce' => $h['nonce'] ?? null, 'expiry' => $h['expiry'] ?? null, 'amount' => $p['amount'] ?? null] as $name => $v) {
            if (!(is_int($v) && $v >= 0) && !(is_string($v) && preg_match('/^(0|[1-9]\d{0,19})$/', $v))) {
                throw new InvalidPaymentException("The $name is not a plain integer.");
            }
        }
        $u64 = static fn (int|string $n): string => str_pad(gmp_export(gmp_init((string) $n, 10)), 8, "\0", \STR_PAD_LEFT);
        $body = "\x03".$to.$u64($p['amount']);
        // Header: bitmap u16 (bit 0 = sponsored), sender, nonce, energy, payload size u32, expiry, sponsor.
        $energy = self::energy($base, $signatures + $sponsorSignatures, 2 + 32 + 8 + 8 + 4 + 8 + 32 + strlen($body));
        $header = pack('n', 1).$sender.$u64($h['nonce']).$u64($energy).pack('N', strlen($body)).$u64($h['expiry']).$sponsor;

        return [
            'sender' => $sender,
            'to' => $to,
            'sponsor' => $sponsor,
            'nonce' => (int) $h['nonce'],
            'expiry' => (int) $h['expiry'],
            'amount' => (int) $p['amount'],
            'energy' => $energy,
            'header' => $header,
            'body' => $body,
        ];
    }

    /**
     * Whether an Ed25519 signature (hex) over the transaction's digest was made by $publicKey (hex).
     */
    public static function signedBy(array $tx, string $signatureHex, string $publicKeyHex): bool
    {
        if (!preg_match('/^[0-9a-f]{128}$/', $signatureHex) || !preg_match('/^[0-9a-f]{64}$/', $publicKeyHex)) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached(hex2bin($signatureHex), self::signHash($tx), hex2bin($publicKeyHex));
        } catch (InvalidPaymentException) {
            return false;
        }
    }
}
