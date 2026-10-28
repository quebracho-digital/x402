<?php

namespace Quebracho\X402;

/**
 * An EIP-3009 TransferWithAuthorization of exactly the price, as an x402 v2
 * payment payload.
 */
final class Authorization {

  public const TYPES = [
    'TransferWithAuthorization' => [
      ['name' => 'from', 'type' => 'address'],
      ['name' => 'to', 'type' => 'address'],
      ['name' => 'value', 'type' => 'uint256'],
      ['name' => 'validAfter', 'type' => 'uint256'],
      ['name' => 'validBefore', 'type' => 'uint256'],
      ['name' => 'nonce', 'type' => 'bytes32'],
    ],
  ];

  /**
   * The EIP-712 digest of an authorization against one payment requirement.
   */
  public static function digest(array $req, array $authorization): string {
    return Eip712::hash(
      [
        'name' => $req['extra']['name'],
        'version' => $req['extra']['version'],
        'chainId' => (int) explode(':', $req['network'])[1],
        'verifyingContract' => $req['asset'],
      ],
      self::TYPES,
      'TransferWithAuthorization',
      $authorization,
    );
  }

  /**
   * Signs a payment for one entry of a 402's `accepts`.
   *
   * @param array $required
   *   The decoded PAYMENT-REQUIRED header.
   * @param array $req
   *   The chosen entry of $required['accepts'].
   */
  public static function pay(Signer $signer, array $required, array $req, ?int $now = NULL, ?string $nonce = NULL): array {
    $authorization = [
      'from' => $signer->address(),
      'to' => $req['payTo'],
      'value' => (string) $req['amount'],
      'validAfter' => '0',
      'validBefore' => (string) (($now ?? time()) + (int) $req['maxTimeoutSeconds']),
      'nonce' => $nonce ?? '0x' . bin2hex(random_bytes(32)),
    ];
    return [
      'x402Version' => 2,
      'resource' => $required['resource'],
      'accepted' => ['extra' => ['assetTransferMethod' => 'eip3009'] + $req['extra']] + $req,
      'payload' => ['signature' => $signer->sign(self::digest($req, $authorization)), 'authorization' => $authorization],
    ];
  }

}
