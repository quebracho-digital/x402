<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * A signed EIP-1559 transaction that calls an ERC-20 token.
 *
 * A transfer(to, amount) for sweeping, or any calldata (settling a buyer's
 * transferWithAuthorization). No value, no access list. Equal byte for
 * byte to viem's signTransaction.
 */
final class Transfer {

  /**
   * The raw transaction, 0x-hex, ready for eth_sendRawTransaction.
   *
   * @param \Quebracho\X402\Signer $from
   *   The sender's key: it pays the gas.
   * @param array $tx
   *   chainId, nonce, maxPriorityFeePerGas, maxFeePerGas, gas (integers or
   *   decimal strings), token (the contract called), and either data
   *   (0x-hex calldata) or to (an address) and amount (atomic units).
   */
  public static function sign(Signer $from, array $tx): string {
    $data = isset($tx['data']) ? Eip712::raw($tx['data']) : Eip712::raw('0xa9059cbb')
      . str_pad(Eip712::raw($tx['to']), 32, "\0", STR_PAD_LEFT)
      . str_pad(self::int($tx['amount']), 32, "\0", STR_PAD_LEFT);
    $fields = [
      self::int($tx['chainId']),
      self::int($tx['nonce']),
      self::int($tx['maxPriorityFeePerGas']),
      self::int($tx['maxFeePerGas']),
      self::int($tx['gas']),
      Eip712::raw($tx['token']),
      '',
      $data,
      [],
    ];
    $signature = Eip712::raw($from->sign(Eip712::keccak("\x02" . self::rlp($fields))));
    $fields[] = self::int(ord($signature[64]) - 27);
    $fields[] = ltrim(substr($signature, 0, 32), "\0");
    $fields[] = ltrim(substr($signature, 32, 32), "\0");
    return '0x02' . bin2hex(self::rlp($fields));
  }

  /**
   * A non-negative integer as minimal big-endian bytes; zero is empty.
   */
  private static function int(int|string $n): string {
    $n = gmp_init((string) $n, 10);
    return gmp_sign($n) === 0 ? '' : gmp_export($n);
  }

  /**
   * Recursive length prefix: a byte string, or a list of them.
   */
  private static function rlp(string|array $item): string {
    if (is_array($item)) {
      $payload = implode('', array_map(self::rlp(...), $item));
      return self::prefix(strlen($payload), 0xc0) . $payload;
    }
    if (strlen($item) === 1 && ord($item) < 0x80) {
      return $item;
    }
    return self::prefix(strlen($item), 0x80) . $item;
  }

  /**
   * The length prefix, short or long.
   *
   * Short: offset + length. Long: offset + 55 + the length's own size, then
   * the length.
   */
  private static function prefix(int $length, int $offset): string {
    if ($length <= 55) {
      return chr($offset + $length);
    }
    $bytes = ltrim(pack('J', $length), "\0");
    return chr($offset + 55 + strlen($bytes)) . $bytes;
  }

}
