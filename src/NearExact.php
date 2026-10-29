<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The `exact` scheme on NEAR chains: a NEP-366 Delegate Action meta-transaction.
 *
 * Follows x402-foundation specs/schemes/exact/scheme_exact_near.md. Experimental.
 * Ed25519 keys only: secp256k1 delegate keys are rejected as invalid_payload.
 */
final class NearExact implements Scheme
{
    public const string TESTNET_NETWORK = 'near:testnet';
    // USDC on NEAR testnet, as in the x402 reference implementation.
    public const string TESTNET_USDC = '3e2210e1184b45b64c8a434c0a7e7b23cc04ea7eb7a6c3c32520d03d4afcb8af';
    public const int MAX_GAS = 100_000_000_000_000;
    public const int NEP_366_DISCRIMINANT = (1 << 30) + 366; // 1073742190

    /**
     * @param ?string   $secretKey
     *                             Raw 32-byte or 64-byte Ed25519 secret key for buyer signing
     * @param ?string   $senderId
     *                             NEAR account ID of the buyer (e.g. "buyer.testnet").
     * @param ?int      $nonce
     *                             Access key nonce for the delegate action (must be > current key nonce)
     * @param ?\Closure $clock
     *                             Returns the current final block height (an RPC `block` call)
     * @param ?\Closure $keyOwner
     *                             fn(string $accountId, string $publicKey): bool — whether that raw
     *                             32-byte ed25519 key is a full-access key of the account (an RPC
     *                             `view_access_key` call). Without it payer() is NULL: unproven.
     */
    public function __construct(
        private readonly ?string $secretKey = null,
        private readonly ?string $senderId = null,
        private readonly ?int $nonce = null,
        private readonly ?\Closure $clock = null,
        private readonly ?\Closure $keyOwner = null,
    ) {
    }

    /**
     * The $keyOwner a seller passes: a full-access key of that account, by
     * the node's view_access_key. A function-call key is not the account.
     */
    public static function keyOwner(Rpc $rpc): \Closure
    {
        return static function (string $accountId, string $publicKey) use ($rpc): bool {
            $out = $rpc('query', ['request_type' => 'view_access_key', 'finality' => 'final', 'account_id' => $accountId, 'public_key' => 'ed25519:'.Solana::encode($publicKey)]);

            // An unknown key answers {error: …} inside the result.
            return is_array($out) && 'FullAccess' === ($out['permission'] ?? null);
        };
    }

    /**
     * The $clock: the latest final block height.
     */
    public static function blockHeight(Rpc $rpc): \Closure
    {
        return static function () use ($rpc): int {
            $height = $rpc('block', ['finality' => 'final'])['header']['height'] ?? null;

            return is_int($height) ? $height : throw new \RuntimeException('block: no height');
        };
    }

    public function supports(string $network): bool
    {
        return self::TESTNET_NETWORK === $network;
    }

    public function pay(array $required, array $req): array
    {
        $secretKey = $this->secretKey ?? throw new \LogicException('Paying needs a buyer secret key.');
        $senderId = $this->senderId ?? throw new \LogicException('Paying needs a sender account ID.');
        $nonce = $this->nonce ?? throw new \LogicException('Paying needs a NEAR nonce.');
        $height = $this->clock ? ($this->clock)() : throw new \LogicException('Paying needs the current block height.');
        // The spec's timeout mapping: one block per second.
        $maxBlockHeight = $height + self::timeoutBlocks($req);

        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('ext-sodium is required for ed25519 signing on NEAR.');
        }

        if (SODIUM_CRYPTO_SIGN_KEYPAIRBYTES === strlen($secretKey)) {
            $kp = $secretKey;
        } elseif (SODIUM_CRYPTO_SIGN_SEEDBYTES === strlen($secretKey)) {
            $kp = sodium_crypto_sign_seed_keypair($secretKey);
        } elseif (SODIUM_CRYPTO_SIGN_SECRETKEYBYTES === strlen($secretKey)) {
            $kp = sodium_crypto_sign_keypair_from_secretkey_and_publickey($secretKey, sodium_crypto_sign_publickey_from_secretkey($secretKey));
        } else {
            throw new \InvalidArgumentException('Secret key must be seed (32 bytes), secretkey (64 bytes), or keypair ('.SODIUM_CRYPTO_SIGN_KEYPAIRBYTES.' bytes).');
        }

        $publicKeyBytes = sodium_crypto_sign_publickey($kp);
        $payTo = (string) ($req['payTo'] ?? '');
        $asset = (string) ($req['asset'] ?? self::TESTNET_USDC);
        $amount = (string) ($req['amount'] ?? '0');

        // Build ft_transfer function call action args JSON using atomic amount directly
        $ftArgsJson = json_encode([
            'receiver_id' => $payTo,
            'amount' => $amount,
            'memo' => 'x402 payment',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Borsh encode DelegateAction
        $delegateActionBorsh = self::encodeDelegateAction(
            $senderId,
            $asset, // receiver_id of delegate action = token contract ID
            'ft_transfer',
            $ftArgsJson,
            30_000_000_000_000, // 30 TGas
            1, // 1 yoctoNEAR deposit required for NEP-141 ft_transfer
            $nonce,
            $maxBlockHeight,
            $publicKeyBytes
        );

        // SignableMessage Borsh encoding: discriminant (4 bytes LE) + delegateActionBorsh
        $signableMessageBorsh = pack('V', self::NEP_366_DISCRIMINANT).$delegateActionBorsh;

        // Hash with SHA-256
        $msgHash = hash('sha256', $signableMessageBorsh, true);

        // Ed25519 signature
        $sigBytes = sodium_crypto_sign_detached($msgHash, sodium_crypto_sign_secretkey($kp));

        $signedDelegateActionBorsh = $delegateActionBorsh."\x00".$sigBytes; // 0x00 tag for Ed25519 signature

        return [
            'x402Version' => 2,
            'resource' => $required['resource'] ?? null,
            'accepted' => $req,
            'payload' => ['signedDelegateAction' => base64_encode($signedDelegateActionBorsh)],
        ];
    }

    public function verify(array $payment, array $req): ?string
    {
        $payload = $payment['payload'] ?? null;
        $accepted = $payment['accepted'] ?? [];

        if (!is_array($payload)) {
            return 'invalid_payload';
        }

        $rawSignedBorsh = is_string($payload['signedDelegateAction'] ?? null)
          ? base64_decode($payload['signedDelegateAction'], true)
          : null;

        if (!$rawSignedBorsh) {
            return 'invalid_payload';
        }

        // Check scheme and network
        if (($payment['x402Version'] ?? null) !== 2 || ($accepted['scheme'] ?? null) !== 'exact' || ($accepted['network'] ?? null) !== $req['network']) {
            return 'unsupported_scheme';
        }
        foreach (['asset', 'payTo', 'amount'] as $field) {
            if ((string) ($accepted[$field] ?? '') !== (string) ($req[$field] ?? '')) {
                return 'invalid_'.('payTo' === $field ? 'recipient' : $field);
            }
        }

        // Decode SignedDelegateAction Borsh
        $decoded = self::decodeSignedDelegateAction($rawSignedBorsh);
        if (!$decoded) {
            return 'invalid_payload';
        }

        // Check expiry
        $currentBlockHeight = $this->clock ? ($this->clock)() : 0;
        $remaining = $decoded['max_block_height'] - $currentBlockHeight;
        if ($currentBlockHeight <= 0 || $remaining <= 0) {
            return 'expired';
        }
        if ($remaining > self::timeoutBlocks($req)) {
            return 'timeout_window_exceeds_maxTimeout';
        }
        // NEAR's own upper bound on delegate-action nonces.
        if ($decoded['nonce'] >= $currentBlockHeight * 1_000_000) {
            return 'invalid_nonce';
        }
        if ($decoded['gas'] > self::MAX_GAS) {
            return 'invalid_payload';
        }

        // Check token contract (asset)
        if (0 !== strcasecmp($decoded['receiver_id'], (string) $req['asset'])) {
            return 'invalid_asset';
        }

        // Check inner action (FunctionCall ft_transfer)
        if ('ft_transfer' !== $decoded['method_name']) {
            return 'invalid_payload';
        }

        $ftArgs = json_decode($decoded['args_json'], true);
        if (!is_array($ftArgs)) {
            return 'invalid_payload';
        }

        // Check recipient
        if (0 !== strcasecmp((string) ($ftArgs['receiver_id'] ?? ''), (string) $req['payTo'])) {
            return 'invalid_recipient';
        }

        // Check amount
        if ((string) ($ftArgs['amount'] ?? '') !== (string) $req['amount']) {
            return 'invalid_amount';
        }

        // ponytail: offline only. The spec's facilitator MUSTs that need RPC
        // (view_access_key: key belongs to sender_id, nonce unused, FullAccess;
        // ft_balance_of, storage_balance_of) are the facilitator's, not ours.
        // Verify Ed25519 signature
        $signableMessageBorsh = pack('V', self::NEP_366_DISCRIMINANT).$decoded['delegate_action_borsh'];
        $msgHash = hash('sha256', $signableMessageBorsh, true);

        if (!extension_loaded('sodium')) {
            return 'invalid_signature';
        }

        $valid = sodium_crypto_sign_verify_detached($decoded['signature_bytes'], $msgHash, $decoded['public_key_bytes']);
        if (!$valid) {
            return 'invalid_signature';
        }

        return null;
    }

    /**
     * The delegate action's sender_id, once $keyOwner proves the signing key
     * is theirs; NULL otherwise.
     *
     * Offline, anyone can write a clean sender_id over their own key, so an
     * unproven payer is no payer: with screening on, it is rejected.
     */
    public function payer(array $payment): ?string
    {
        $raw = $payment['payload']['signedDelegateAction'] ?? null;
        $bytes = is_string($raw) ? base64_decode($raw, true) : false;
        $decoded = $bytes ? self::decodeSignedDelegateAction($bytes) : null;
        if (null === $decoded || null === $this->keyOwner) {
            return null;
        }
        try {
            return ($this->keyOwner)($decoded['sender_id'], $decoded['public_key_bytes']) === true ? $decoded['sender_id'] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function timeoutBlocks(array $req): int
    {
        return max(1, (int) ($req['maxTimeoutSeconds'] ?? 0));
    }

    /**
     * Encodes a NEAR DelegateAction into Borsh binary format.
     */
    public static function encodeDelegateAction(
        string $senderId,
        string $receiverId,
        string $methodName,
        string $argsJson,
        int $gas,
        int $deposit,
        int $nonce,
        int $maxBlockHeight,
        string $publicKeyBytes,
    ): string {
        $out = '';
        // sender_id
        $out .= self::encodeString($senderId);
        // receiver_id
        $out .= self::encodeString($receiverId);

        // actions: Vec<NonDelegateAction> -> 1 action
        $out .= pack('V', 1);

        // Action::FunctionCall (u8 discriminant = 2)
        $out .= "\x02";
        $out .= self::encodeString($methodName);
        $out .= self::encodeBytes($argsJson);
        $out .= pack('P', $gas); // u64 LE

        // deposit: u128 LE (16 bytes)
        $out .= pack('P', $deposit).str_repeat("\x00", 8);

        // nonce: u64 LE
        $out .= pack('P', $nonce);
        // max_block_height: u64 LE
        $out .= pack('P', $maxBlockHeight);

        // public_key: PublicKey (ED25519 tag = 0 + 32 bytes)
        $out .= "\x00".$publicKeyBytes;

        return $out;
    }

    /**
     * Decodes a SignedDelegateAction Borsh binary stream.
     */
    public static function decodeSignedDelegateAction(string $bytes): ?array
    {
        $offset = 0;
        $len = strlen($bytes);

        try {
            $delegateActionStart = $offset;

            $senderId = self::readString($bytes, $offset);
            $receiverId = self::readString($bytes, $offset);

            $actionCount = self::readU32($bytes, $offset);
            if (1 !== $actionCount) {
                return null;
            }

            $actionType = ord($bytes[$offset++]);
            if (2 !== $actionType) { // FunctionCall
                return null;
            }

            $methodName = self::readString($bytes, $offset);
            $argsJson = self::readBytes($bytes, $offset);
            $gas = self::readU64($bytes, $offset);

            // deposit: u128 (16 bytes); ft_transfer takes exactly 1 yoctoNEAR.
            if ($offset + 16 > $len) {
                return null;
            }
            if (substr($bytes, $offset, 16) !== "\x01".str_repeat("\x00", 15)) {
                return null;
            }
            $offset += 16;

            $nonce = self::readU64($bytes, $offset);
            $maxBlockHeight = self::readU64($bytes, $offset);

            // public_key: tag u8 + 32 bytes
            if ($offset + 33 > $len) {
                return null;
            }
            $keyTag = ord($bytes[$offset++]);
            if (0 !== $keyTag) {
                return null;
            } // Ed25519 only
            $publicKeyBytes = substr($bytes, $offset, 32);
            $offset += 32;

            $delegateActionEnd = $offset;
            $delegateActionBorsh = substr($bytes, $delegateActionStart, $delegateActionEnd - $delegateActionStart);

            // Signature: tag u8 + 64 bytes
            if ($offset + 65 > $len) {
                return null;
            }
            $sigTag = ord($bytes[$offset++]);
            if (0 !== $sigTag) {
                return null;
            } // Ed25519
            $sigBytes = substr($bytes, $offset, 64);
            // Trailing bytes would make the same payment look new to the claim.
            if ($offset + 64 !== $len) {
                return null;
            }

            return [
                'sender_id' => $senderId,
                'receiver_id' => $receiverId,
                'method_name' => $methodName,
                'args_json' => $argsJson,
                'gas' => $gas,
                'nonce' => $nonce,
                'max_block_height' => $maxBlockHeight,
                'public_key_bytes' => $publicKeyBytes,
                'signature_bytes' => $sigBytes,
                'delegate_action_borsh' => $delegateActionBorsh,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function encodeString(string $s): string
    {
        return pack('V', strlen($s)).$s;
    }

    private static function encodeBytes(string $b): string
    {
        return pack('V', strlen($b)).$b;
    }

    private static function readU32(string $bytes, int &$offset): int
    {
        if ($offset + 4 > strlen($bytes)) {
            throw new \OutOfBoundsException();
        }
        $val = unpack('V', substr($bytes, $offset, 4))[1];
        $offset += 4;

        return $val;
    }

    private static function readU64(string $bytes, int &$offset): int
    {
        if ($offset + 8 > strlen($bytes)) {
            throw new \OutOfBoundsException();
        }
        $val = unpack('P', substr($bytes, $offset, 8))[1];
        $offset += 8;

        return $val;
    }

    private static function readString(string $bytes, int &$offset): string
    {
        $len = self::readU32($bytes, $offset);
        if ($offset + $len > strlen($bytes)) {
            throw new \OutOfBoundsException();
        }
        $val = substr($bytes, $offset, $len);
        $offset += $len;

        return $val;
    }

    private static function readBytes(string $bytes, int &$offset): string
    {
        return self::readString($bytes, $offset);
    }
}
