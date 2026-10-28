<?php

namespace Quebracho\X402;

/**
 * The `exact` scheme on NEAR chains: a NEP-366 Delegate Action meta-transaction.
 */
final class NearExact implements Scheme {

  public const string TESTNET_NETWORK = 'near:testnet';
  public const string TESTNET_USDC = 'usdc.fakes.testnet';
  public const int NEP_366_DISCRIMINANT = (1 << 30) + 366; // 1073742190

  /**
   * @param ?string $secretKey
   *   Raw 32-byte or 64-byte Ed25519 secret key for buyer signing.
   * @param ?string $senderId
   *   NEAR account ID of the buyer (e.g. "buyer.testnet").
   * @param ?int $nonce
   *   Access key nonce for the delegate action (must be > current key nonce).
   * @param ?int $maxBlockHeight
   *   Expiration block height for the delegate action.
   * @param ?\Closure $clock
   *   Optional clock callback returning block height/time for testing.
   */
  public function __construct(
    private readonly ?string $secretKey = NULL,
    private readonly ?string $senderId = NULL,
    private readonly ?int $nonce = NULL,
    private readonly ?int $maxBlockHeight = NULL,
    private readonly ?\Closure $clock = NULL,
  ) {}

  public function supports(string $network): bool {
    return $network === self::TESTNET_NETWORK;
  }

  public function pay(array $required, array $req): array {
    $secretKey = $this->secretKey ?? throw new \LogicException('Paying needs a buyer secret key.');
    $senderId = $this->senderId ?? throw new \LogicException('Paying needs a sender account ID.');
    $nonce = $this->nonce ?? throw new \LogicException('Paying needs a NEAR nonce.');
    $maxBlockHeight = $this->maxBlockHeight ?? throw new \LogicException('Paying needs a max block height.');

    if (!extension_loaded('sodium')) {
      throw new \RuntimeException('ext-sodium is required for ed25519 signing on NEAR.');
    }

    if (strlen($secretKey) === SODIUM_CRYPTO_SIGN_KEYPAIRBYTES) {
      $kp = $secretKey;
    } elseif (strlen($secretKey) === SODIUM_CRYPTO_SIGN_SEEDBYTES) {
      $kp = sodium_crypto_sign_seed_keypair($secretKey);
    } elseif (strlen($secretKey) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
      $kp = sodium_crypto_sign_keypair_from_secretkey_and_publickey($secretKey, sodium_crypto_sign_publickey_from_secretkey($secretKey));
    } else {
      throw new \InvalidArgumentException('Secret key must be seed (32 bytes), secretkey (64 bytes), or keypair (' . SODIUM_CRYPTO_SIGN_KEYPAIRBYTES . ' bytes).');
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
    $signableMessageBorsh = pack('V', self::NEP_366_DISCRIMINANT) . $delegateActionBorsh;

    // Hash with SHA-256
    $msgHash = hash('sha256', $signableMessageBorsh, TRUE);

    // Ed25519 signature
    $sigBytes = sodium_crypto_sign_detached($msgHash, sodium_crypto_sign_secretkey($kp));

    $signedDelegateActionBorsh = $delegateActionBorsh . "\x00" . $sigBytes; // 0x00 tag for Ed25519 signature

    return [
      'accepted' => $req,
      'payload' => [
        'signed_delegate_action' => base64_encode($signedDelegateActionBorsh),
        'signed_delegate_action_hex' => bin2hex($signedDelegateActionBorsh),
        'sender_id' => $senderId,
        'public_key' => 'ed25519:' . self::base58Encode($publicKeyBytes),
        'nonce' => $nonce,
        'max_block_height' => $maxBlockHeight,
      ],
    ];
  }

  public function verify(array $payment, array $req): ?string {
    $payload = $payment['payload'] ?? NULL;
    $accepted = $payment['accepted'] ?? [];

    if (!is_array($payload)) {
      return 'invalid_payload';
    }

    $rawSignedBorsh = NULL;
    if (isset($payload['signed_delegate_action']) && is_string($payload['signed_delegate_action'])) {
      $rawSignedBorsh = base64_decode($payload['signed_delegate_action'], TRUE);
    }
    if (!$rawSignedBorsh && isset($payload['signed_delegate_action_hex']) && is_string($payload['signed_delegate_action_hex'])) {
      $rawSignedBorsh = hex2bin($payload['signed_delegate_action_hex']);
    }

    if (!$rawSignedBorsh || !is_string($rawSignedBorsh)) {
      return 'invalid_payload';
    }

    // Check scheme and network
    if (($accepted['scheme'] ?? NULL) !== 'exact' || ($accepted['network'] ?? NULL) !== $req['network']) {
      return 'unsupported_scheme';
    }

    // Decode SignedDelegateAction Borsh
    $decoded = self::decodeSignedDelegateAction($rawSignedBorsh);
    if (!$decoded) {
      return 'invalid_payload';
    }

    // Check expiry
    $currentBlockHeight = $this->clock ? ($this->clock)() : 0;
    if ($currentBlockHeight <= 0) {
      return 'expired';
    }
    if ($decoded['max_block_height'] < $currentBlockHeight) {
      return 'expired';
    }

    // Check token contract (asset)
    if (strcasecmp($decoded['receiver_id'], (string) $req['asset']) !== 0) {
      return 'invalid_asset';
    }

    // Check inner action (FunctionCall ft_transfer)
    if ($decoded['method_name'] !== 'ft_transfer') {
      return 'invalid_payload';
    }

    $ftArgs = json_decode($decoded['args_json'], TRUE);
    if (!is_array($ftArgs)) {
      return 'invalid_payload';
    }

    // Check recipient
    if (strcasecmp((string) ($ftArgs['receiver_id'] ?? ''), (string) $req['payTo']) !== 0) {
      return 'invalid_recipient';
    }

    // Check amount
    if ((string) ($ftArgs['amount'] ?? '') !== (string) $req['amount']) {
      return 'invalid_amount';
    }

    // ponytail: the public key is not tied to sender_id here (that needs an
    // RPC view_access_key); the network rejects a foreign key on settle.
    // Verify Ed25519 signature
    $signableMessageBorsh = pack('V', self::NEP_366_DISCRIMINANT) . $decoded['delegate_action_borsh'];
    $msgHash = hash('sha256', $signableMessageBorsh, TRUE);

    if (!extension_loaded('sodium')) {
      return 'invalid_signature';
    }

    $valid = sodium_crypto_sign_verify_detached($decoded['signature_bytes'], $msgHash, $decoded['public_key_bytes']);
    if (!$valid) {
      return 'invalid_signature';
    }

    return NULL;
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
    string $publicKeyBytes
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
    $out .= pack('P', $deposit) . str_repeat("\x00", 8);

    // nonce: u64 LE
    $out .= pack('P', $nonce);
    // max_block_height: u64 LE
    $out .= pack('P', $maxBlockHeight);

    // public_key: PublicKey (ED25519 tag = 0 + 32 bytes)
    $out .= "\x00" . $publicKeyBytes;

    return $out;
  }

  /**
   * Decodes a SignedDelegateAction Borsh binary stream.
   */
  public static function decodeSignedDelegateAction(string $bytes): ?array {
    $offset = 0;
    $len = strlen($bytes);

    try {
      $delegateActionStart = $offset;

      $senderId = self::readString($bytes, $offset);
      $receiverId = self::readString($bytes, $offset);

      $actionCount = self::readU32($bytes, $offset);
      if ($actionCount !== 1) {
        return NULL;
      }

      $actionType = ord($bytes[$offset++]);
      if ($actionType !== 2) { // FunctionCall
        return NULL;
      }

      $methodName = self::readString($bytes, $offset);
      $argsJson = self::readBytes($bytes, $offset);
      $gas = self::readU64($bytes, $offset);

      // deposit: u128 (16 bytes); ft_transfer takes exactly 1 yoctoNEAR.
      if ($offset + 16 > $len) return NULL;
      if (substr($bytes, $offset, 16) !== "\x01" . str_repeat("\x00", 15)) return NULL;
      $offset += 16;

      $nonce = self::readU64($bytes, $offset);
      $maxBlockHeight = self::readU64($bytes, $offset);

      // public_key: tag u8 + 32 bytes
      if ($offset + 33 > $len) return NULL;
      $keyTag = ord($bytes[$offset++]);
      if ($keyTag !== 0) return NULL; // Ed25519 only
      $publicKeyBytes = substr($bytes, $offset, 32);
      $offset += 32;

      $delegateActionEnd = $offset;
      $delegateActionBorsh = substr($bytes, $delegateActionStart, $delegateActionEnd - $delegateActionStart);

      // Signature: tag u8 + 64 bytes
      if ($offset + 65 > $len) return NULL;
      $sigTag = ord($bytes[$offset++]);
      if ($sigTag !== 0) return NULL; // Ed25519
      $sigBytes = substr($bytes, $offset, 64);

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
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  private static function encodeString(string $s): string {
    return pack('V', strlen($s)) . $s;
  }

  private static function encodeBytes(string $b): string {
    return pack('V', strlen($b)) . $b;
  }

  private static function readU32(string $bytes, int &$offset): int {
    if ($offset + 4 > strlen($bytes)) throw new \OutOfBoundsException();
    $val = unpack('V', substr($bytes, $offset, 4))[1];
    $offset += 4;
    return $val;
  }

  private static function readU64(string $bytes, int &$offset): int {
    if ($offset + 8 > strlen($bytes)) throw new \OutOfBoundsException();
    $val = unpack('P', substr($bytes, $offset, 8))[1];
    $offset += 8;
    return $val;
  }

  private static function readString(string $bytes, int &$offset): string {
    $len = self::readU32($bytes, $offset);
    if ($offset + $len > strlen($bytes)) throw new \OutOfBoundsException();
    $val = substr($bytes, $offset, $len);
    $offset += $len;
    return $val;
  }

  private static function readBytes(string $bytes, int &$offset): string {
    return self::readString($bytes, $offset);
  }

  private static function base58Encode(string $bytes): string {
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $num = gmp_import($bytes, 1, GMP_MSW_FIRST | GMP_BIG_ENDIAN);
    $zero = gmp_init(0);
    $fiftyEight = gmp_init(58);
    $res = '';
    while (gmp_cmp($num, $zero) > 0) {
      [$num, $rem] = gmp_div_qr($num, $fiftyEight);
      $res = $alphabet[gmp_intval($rem)] . $res;
    }
    for ($i = 0; $i < strlen($bytes) && $bytes[$i] === "\x00"; $i++) {
      $res = '1' . $res;
    }
    return $res;
  }

}
