<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The chains and tokens a price can be charged in.
 */
final class Networks {

  /**
   * CAIP-2 network => its USDC.
   *
   * `extra` is the token's EIP-712 domain (on Solana the facilitator adds
   * its fee payer); `rpc` a public node, for sweeping; `explorer` where
   * people see a transaction.
   */
  public const ALL = [
    'eip155:5042002' => [
      'label' => 'Arc testnet (USDC)',
      'asset' => '0x3600000000000000000000000000000000000000',
      'decimals' => 6,
      'extra' => ['name' => 'USDC', 'version' => '2'],
      'rpc' => 'https://rpc.testnet.arc.network',
      'explorer' => 'https://testnet.arcscan.app/tx/',
    ],
    'eip155:5042' => [
      'label' => 'Arc (USDC)',
      'asset' => '0x3600000000000000000000000000000000000000',
      'decimals' => 6,
      'extra' => ['name' => 'USDC', 'version' => '2'],
      'rpc' => 'https://rpc.mainnet.arc.io',
      'explorer' => 'https://explorer.arc.io/tx/',
    ],
    // cspell:disable-next-line
    'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1' => [
      'label' => 'Solana devnet (USDC)',
      // cspell:disable-next-line
      'asset' => '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU',
      'decimals' => 6,
      'extra' => [],
      'rpc' => 'https://api.devnet.solana.com',
      // ponytail: opens on mainnet, where devnet's tx is one cluster switch
      // away; explorers put the cluster after the hash, links put it before.
      'explorer' => 'https://explorer.solana.com/tx/',
    ],
    // cspell:disable-next-line
    'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp' => [
      'label' => 'Solana (USDC)',
      // cspell:disable-next-line
      'asset' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
      'decimals' => 6,
      'extra' => [],
      'rpc' => 'https://api.mainnet-beta.solana.com',
      'explorer' => 'https://solscan.io/tx/',
    ],
  ];

  /**
   * How long the buyer's signed payment stays valid, in seconds, by default.
   *
   * It must outlive the delivery: settling happens after it.
   */
  public const TIMEOUT = 60;

  /**
   * One PaymentRequirements for a price in dollars.
   *
   * @throws \InvalidArgumentException
   *   Unknown network, or a price the token cannot represent.
   */
  public static function requirements(string $network, string $usd, string $payTo, int $timeout = self::TIMEOUT): array {
    $token = self::ALL[$network] ?? throw new \InvalidArgumentException("Unsupported network: $network.");
    return [
      'scheme' => 'exact',
      'network' => $network,
      'amount' => self::atomic($usd, $token['decimals']),
      'asset' => $token['asset'],
      'payTo' => $payTo,
      'maxTimeoutSeconds' => $timeout,
      'extra' => $token['extra'],
    ];
  }

  /**
   * A positive decimal dollar amount in the token's atomic units, exactly.
   *
   * Plain decimal strings only: no exponent, no comma, no more decimals than
   * the token has, since rounding would charge something else.
   *
   * @throws \InvalidArgumentException
   */
  public static function atomic(string $usd, int $decimals): string {
    if (!preg_match('/^(\d+)(?:\.(\d{1,' . $decimals . '}))?$/', $usd, $m)) {
      throw new \InvalidArgumentException("Not a price with at most $decimals decimals: $usd.");
    }
    $atomic = gmp_init(ltrim($m[1] . str_pad($m[2] ?? '', $decimals, '0'), '0') ?: '0', 10);
    if (gmp_sign($atomic) <= 0) {
      throw new \InvalidArgumentException('A price must be above zero.');
    }
    return gmp_strval($atomic);
  }

}
