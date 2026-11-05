<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The `exact` scheme on Casper: a CEP-18 TransferWithAuthorization signed
 * as Casper EIP-712 typed data (casper-ecosystem/casper-eip-712).
 *
 * Follows @make-software/casper-x402, what the CSPR.cloud facilitator runs:
 * the domain is (name, version, chain_name, contract_package_hash), with the
 * CAIP-2 network as chain_name; an address is the 33-byte account hash
 * (00 ‖ blake2b-256) and is encoded as its keccak; the signature is the
 * Casper algorithm byte followed by the 64 signature bytes. The facilitator
 * submits the transfer_with_authorization and pays the gas. Experimental.
 * Ed25519 keys only: secp256k1 (02…) keys are rejected as invalid_payload.
 */
final class CasperExact implements Scheme
{
    public const string MAINNET_NETWORK = 'casper:casper';
    public const string TESTNET_NETWORK = 'casper:casper-test';
    // wCSPR, like CSPR, has 9 decimals: 1 CSPR = 1_000_000_000 motes.
    public const int DECIMALS = 9;

    private const string DOMAIN_TYPE = 'EIP712Domain(string name,string version,string chain_name,bytes32 contract_package_hash)';
    private const string TRANSFER_TYPE = 'TransferWithAuthorization(address from,address to,uint256 value,uint256 validAfter,uint256 validBefore,bytes32 nonce)';
    private const string ED25519 = '01';

    /**
     * @param ?string   $secretKey
     *                             Raw 32-byte Ed25519 seed (or 64-byte secret key) of the buyer
     * @param ?\Closure $clock
     *                             Returns the current Unix time; tests pin it
     * @param ?\Closure $nonce
     *                             Returns 32 random bytes; tests pin it
     */
    public function __construct(
        #[\SensitiveParameter] private readonly ?string $secretKey = null,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $nonce = null,
    ) {
    }

    public function supports(string $network): bool
    {
        return self::MAINNET_NETWORK === $network || self::TESTNET_NETWORK === $network;
    }

    public function pay(array $required, array $req): array
    {
        $secretKey = $this->secretKey ?? throw new \LogicException('Paying needs a buyer secret key.');
        $kp = match (strlen($secretKey)) {
            SODIUM_CRYPTO_SIGN_SEEDBYTES => sodium_crypto_sign_seed_keypair($secretKey),
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES => sodium_crypto_sign_keypair_from_secretkey_and_publickey($secretKey, sodium_crypto_sign_publickey_from_secretkey($secretKey)),
            default => throw new \InvalidArgumentException('An Ed25519 secret key is a 32-byte seed or a 64-byte secret key.'),
        };
        if (!self::isPackageHash((string) ($req['asset'] ?? '')) || !self::isAddress((string) ($req['payTo'] ?? ''))) {
            throw new \InvalidArgumentException('A Casper offer names a 64-hex contract package hash and a 00/01-prefixed payTo.');
        }
        if (empty($req['extra']['name']) || empty($req['extra']['version'])) {
            throw new \InvalidArgumentException('A Casper offer needs extra.name and extra.version (the token\'s EIP-712 domain).');
        }
        $now = $this->now();
        $publicKey = self::ED25519.bin2hex(sodium_crypto_sign_publickey($kp));
        $auth = [
            'from' => self::accountAddress($publicKey),
            'to' => (string) $req['payTo'],
            'value' => (string) ($req['amount'] ?? ''),
            // As the reference client: valid from ten minutes ago, to absorb clock skew.
            'validAfter' => (string) ($now - 600),
            'validBefore' => (string) ($now + (int) ($req['maxTimeoutSeconds'] ?? 0)),
            'nonce' => bin2hex($this->nonce ? ($this->nonce)() : random_bytes(32)),
        ];
        $signature = sodium_crypto_sign_detached(Eip712::raw(self::digest($req, $auth)), sodium_crypto_sign_secretkey($kp));

        return [
            'x402Version' => 2,
            'resource' => $required['resource'] ?? null,
            'accepted' => $req,
            'payload' => ['signature' => self::ED25519.bin2hex($signature), 'publicKey' => $publicKey, 'authorization' => $auth],
        ];
    }

    public function verify(array $payment, array $req): ?string
    {
        $auth = $payment['payload']['authorization'] ?? null;
        $signature = $payment['payload']['signature'] ?? null;
        $publicKey = $payment['payload']['publicKey'] ?? null;
        $accepted = $payment['accepted'] ?? [];
        $now = $this->now();

        return match (true) {
            !is_array($auth) || !is_string($signature) || !is_string($publicKey) || !is_array($accepted) => 'invalid_payload',
            // One spelling per value (lowercase hex, plain decimals): the claim
            // key (Settlement::key) must not see one signature as several payments.
            !self::canonical($auth, $signature, $publicKey) => 'invalid_payload',
            ($payment['x402Version'] ?? null) !== 2 => 'invalid_x402_version',
            ($accepted['scheme'] ?? null) !== 'exact' => 'invalid_scheme',
            ($accepted['network'] ?? null) !== $req['network'] || !$this->supports((string) $req['network']) => 'invalid_network',
            empty($req['extra']['name']) || empty($req['extra']['version']) || !self::isPackageHash((string) ($req['asset'] ?? '')) => 'invalid_payment_requirements',
            !preg_match('/^[1-9][0-9]*$/', (string) ($req['amount'] ?? '')) => 'invalid_payment_requirements',
            (string) ($accepted['asset'] ?? '') !== (string) $req['asset']
              || (string) ($accepted['payTo'] ?? '') !== (string) $req['payTo']
              || (string) ($accepted['amount'] ?? '') !== (string) $req['amount'] => 'invalid_payload',
            // Exact, as the facilitator compares them.
            $auth['to'] !== $req['payTo'] => 'invalid_exact_casper_payload_recipient_mismatch',
            $auth['value'] !== (string) $req['amount'] => 'invalid_exact_casper_payload_authorization_value_mismatch',
            (int) $auth['validAfter'] > $now => 'invalid_exact_casper_payload_authorization_valid_after',
            // The facilitator wants 6 s left to settle.
            (int) $auth['validBefore'] < $now + 6 => 'invalid_exact_casper_payload_authorization_valid_before',
            // No longer than the offer allows (+60 s clock skew): bounds replay windows and claim storage.
            (int) $auth['validBefore'] > $now + (int) ($req['maxTimeoutSeconds'] ?? 0) + 60 => 'invalid_exact_casper_payload_authorization_valid_before',
            self::accountAddress($publicKey) !== $auth['from'] => 'invalid_exact_casper_payload_publickey_mismatch',
            !sodium_crypto_sign_verify_detached(Eip712::raw(substr($signature, 2)), Eip712::raw(self::digest($req, $auth)), Eip712::raw(substr($publicKey, 2))) => 'invalid_exact_casper_payload_signature',
            default => null,
        };
    }

    /**
     * The authorization's `from` (00 ‖ account hash), the account the token debits.
     */
    public function payer(array $payment): ?string
    {
        $from = $payment['payload']['authorization']['from'] ?? null;

        return is_string($from) ? $from : null;
    }

    /**
     * The EIP-712 digest the buyer signs, as 0x-prefixed hex.
     *
     * @param array $req
     *                    The offer: network, asset (contract package hash), extra.name, extra.version
     * @param array $auth
     *                    from, to, value, validAfter, validBefore, nonce, all hex without 0x or decimal
     */
    public static function digest(array $req, array $auth): string
    {
        $domain = Eip712::raw(Eip712::keccak(self::DOMAIN_TYPE))
            .Eip712::raw(Eip712::keccak((string) $req['extra']['name']))
            .Eip712::raw(Eip712::keccak((string) $req['extra']['version']))
            // The CAIP-2 id, "casper:casper-test", as the reference passes it.
            .Eip712::raw(Eip712::keccak((string) $req['network']))
            .self::bytes32((string) $req['asset']);
        $struct = Eip712::raw(Eip712::keccak(self::TRANSFER_TYPE))
            .Eip712::raw(Eip712::keccak(Eip712::raw((string) $auth['from'])))
            .Eip712::raw(Eip712::keccak(Eip712::raw((string) $auth['to'])))
            .self::uint256((string) $auth['value'])
            .self::uint256((string) $auth['validAfter'])
            .self::uint256((string) $auth['validBefore'])
            .self::bytes32((string) $auth['nonce']);

        return Eip712::keccak("\x19\x01".Eip712::raw(Eip712::keccak($domain)).Eip712::raw(Eip712::keccak($struct)));
    }

    /**
     * The account address of a Casper public key: 00 ‖ blake2b-256(algorithm ‖ 0x00 ‖ key).
     *
     * @param string $publicKey
     *                          Hex with its algorithm byte: 01 (ed25519) or 02 (secp256k1)
     */
    public static function accountAddress(string $publicKey): string
    {
        $algorithm = match (substr($publicKey, 0, 2)) {
            '01' => 'ed25519',
            '02' => 'secp256k1',
            default => throw new \InvalidArgumentException('A Casper public key starts with 01 or 02.'),
        };

        return '00'.bin2hex(sodium_crypto_generichash($algorithm."\x00".Eip712::raw(substr($publicKey, 2)), '', 32));
    }

    /**
     * An account (00) or package (01) hash, lowercase: what payTo and from are.
     */
    public static function isAddress(string $s): bool
    {
        return 1 === preg_match('/^0[01][0-9a-f]{64}$/', $s);
    }

    /**
     * A CEP-18 contract package hash: 64 lowercase hex, no prefix.
     */
    public static function isPackageHash(string $s): bool
    {
        return 1 === preg_match('/^[0-9a-f]{64}$/', $s);
    }

    private static function canonical(array $auth, string $signature, string $publicKey): bool
    {
        $formats = ['from' => '/^00[0-9a-f]{64}$/', 'to' => '/^0[01][0-9a-f]{64}$/', 'nonce' => '/^[0-9a-f]{64}$/', 'value' => '/^(0|[1-9][0-9]{0,77})$/', 'validAfter' => '/^(0|[1-9][0-9]{0,18})$/', 'validBefore' => '/^(0|[1-9][0-9]{0,18})$/'];
        foreach ($formats as $key => $format) {
            if (!is_string($auth[$key] ?? null) || !preg_match($format, $auth[$key])) {
                return false;
            }
        }

        return preg_match('/^01[0-9a-f]{64}$/', $publicKey) && preg_match('/^01[0-9a-f]{128}$/', $signature);
    }

    private static function bytes32(string $hex): string
    {
        $raw = Eip712::raw($hex);

        return 32 === strlen($raw) ? $raw : throw new \InvalidArgumentException('Not 32 bytes.');
    }

    private static function uint256(string $decimal): string
    {
        return str_pad(gmp_export(gmp_init($decimal, 10)), 32, "\0", STR_PAD_LEFT);
    }

    private function now(): int
    {
        return $this->clock ? ($this->clock)() : time();
    }
}
