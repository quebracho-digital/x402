<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The chains and tokens a price can be charged in.
 */
final class Networks
{
    /**
     * CAIP-2 network => its USDC.
     *
     * `extra` is the token's EIP-712 domain (the facilitator adds
     * its fee payer); `rpc` a public node, for sweeping; `explorer` where
     * people see a transaction.
     */
    // Testnets only: this library never moves real money.
    public const ALL = [
        'eip155:84532' => [
            'label' => 'Base Sepolia (USDC)',
            'asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
            'decimals' => 6,
            'extra' => ['name' => 'USDC', 'version' => '2'],
            'rpc' => 'https://sepolia.base.org',
            'explorer' => 'https://sepolia.basescan.org/tx/',
        ],
        'eip155:5042002' => [
            'label' => 'Arc testnet (USDC)',
            'asset' => '0x3600000000000000000000000000000000000000',
            'decimals' => 6,
            'extra' => ['name' => 'USDC', 'version' => '2'],
            'rpc' => 'https://rpc.testnet.arc.network',
            'explorer' => 'https://testnet.arcscan.app/tx/',
        ],
        // ponytail: addresses and EIP-712 domains below are unverified against
        // the contracts; the first testnet payment on each one confirms them.
        'eip155:11155111' => [
            'label' => 'Ethereum Sepolia (USDC)',
            'asset' => '0x1c7D4B196Cb0C7B01d743Fbc6116a902379C7238',
            'decimals' => 6,
            'extra' => ['name' => 'USDC', 'version' => '2'],
            'rpc' => 'https://ethereum-sepolia-rpc.publicnode.com',
            'explorer' => 'https://sepolia.etherscan.io/tx/',
        ],
        'eip155:43113' => [
            'label' => 'Avalanche Fuji (USDC)',
            'asset' => '0x5425890298aed601595a70AB815c96711a31Bc65',
            'decimals' => 6,
            'extra' => ['name' => 'USD Coin', 'version' => '2'],
            'rpc' => 'https://api.avax-test.network/ext/bc/C/rpc',
            'explorer' => 'https://testnet.snowtrace.io/tx/',
        ],
        'eip155:80002' => [
            'label' => 'Polygon Amoy (USDC)',
            'asset' => '0x41E94Eb019C0762f9Bfcf9Fb1E58725BfB0e7582',
            'decimals' => 6,
            'extra' => ['name' => 'USDC', 'version' => '2'],
            'rpc' => 'https://rpc-amoy.polygon.technology',
            'explorer' => 'https://amoy.polygonscan.com/tx/',
        ],
        // Solana devnet, as x402.org/facilitator names it (genesis hash). `asset` is
        // the USDC mint; the facilitator's fee payer goes in `extra` per offer
        // (HttpFacilitator::feePayer).
        'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1' => [
            'label' => 'Solana devnet (USDC)',
            'asset' => '4zMMC9srt5Ri5X14GAgXhaHii3GnPAEERYPJgZJDncDU',
            'decimals' => 6,
            'extra' => [],
            'rpc' => 'https://api.devnet.solana.com',
            'explorer' => 'https://explorer.solana.com/tx/',
        ],
    ];

    /**
     * Networks added at runtime with add()/addSolana(), on top of ALL.
     *
     * ponytail: process-global, so a site registers its networks once at boot
     * (mainnets included: that is the supported way); tests call reset().
     */
    private static array $added = [];

    /**
     * Every network, built in and added: id => settings.
     */
    public static function all(): array
    {
        return self::$added + self::ALL;
    }

    public static function has(string $network): bool
    {
        return isset(self::$added[$network]) || isset(self::ALL[$network]);
    }

    /**
     * One network's settings, or NULL when unknown (get() throws).
     */
    public static function find(string $network): ?array
    {
        return self::$added[$network] ?? self::ALL[$network] ?? null;
    }

    /**
     * Forgets every added network.
     */
    public static function reset(): void
    {
        self::$added = [];
    }

    /**
     * Adds a Solana cluster (its genesis hash) and SPL token mint.
     *
     * @throws \InvalidArgumentException
     *                                   Not base58 of the right length
     */
    public static function addSolana(string $genesis, string $mint, string $label = '', int $decimals = 6, string $rpc = '', string $explorer = ''): string
    {
        if (!self::isSolanaAddress($genesis) || !self::isSolanaAddress($mint)) {
            throw new \InvalidArgumentException('A genesis hash and a mint are base58, 32 to 44 characters.');
        }
        $network = "solana:$genesis";
        self::$added[$network] = ['label' => $label ?: $network, 'asset' => $mint, 'decimals' => $decimals, 'extra' => [], 'rpc' => $rpc, 'explorer' => $explorer];

        return $network;
    }

    private static function isSolanaAddress(string $s): bool
    {
        return 1 === preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $s);
    }

    /**
     * Adds any EVM chain whose token implements EIP-3009.
     *
     * $name and $version are the token's EIP-712 domain: read them from the
     * contract (name(), version()), since a wrong one makes every signature
     * invalid. Whether the chain is a testnet is the caller's responsibility.
     *
     * @throws \InvalidArgumentException
     *                                   A malformed chain id or token address
     */
    public static function add(int $chainId, string $asset, string $name, string $version, int $decimals = 6, string $label = '', string $rpc = '', string $explorer = ''): string
    {
        if ($chainId <= 0 || !preg_match('/^0x[0-9a-fA-F]{40}$/', $asset)) {
            throw new \InvalidArgumentException('A chain id is positive and a token address is 0x followed by 40 hex characters.');
        }
        $network = "eip155:$chainId";
        self::$added[$network] = [
            'label' => $label ?: $network,
            'asset' => $asset,
            'decimals' => $decimals,
            'extra' => ['name' => $name, 'version' => $version],
            'rpc' => $rpc,
            'explorer' => $explorer,
        ];

        return $network;
    }

    /**
     * One network's settings, built in or added.
     *
     * @throws \InvalidArgumentException
     */
    public static function get(string $network): array
    {
        return self::$added[$network] ?? self::ALL[$network] ?? throw new \InvalidArgumentException("Unsupported network: $network.");
    }

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
     *                                   Unknown network, or a price the token cannot represent
     */
    public static function requirements(string $network, string $usd, string $payTo, int $timeout = self::TIMEOUT): array
    {
        $token = self::get($network);
        $evm = str_starts_with($network, 'eip155:');
        if ($evm ? !preg_match('/^0x[0-9a-fA-F]{40}$/', $payTo) : !self::isSolanaAddress($payTo)) {
            throw new \InvalidArgumentException("Not a payTo address on $network: $payTo.");
        }

        return [
            'scheme' => 'exact',
            'network' => $network,
            'amount' => self::atomic($usd, $token['decimals']),
            'asset' => $token['asset'],
            'payTo' => $evm ? Signer::checksum($payTo) : $payTo,
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
    public static function atomic(string $usd, int $decimals): string
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,'.$decimals.'}))?$/', $usd, $m)) {
            throw new \InvalidArgumentException("Not a price with at most $decimals decimals: $usd.");
        }
        $atomic = gmp_init(ltrim($m[1].str_pad($m[2] ?? '', $decimals, '0'), '0') ?: '0', 10);
        if (gmp_sign($atomic) <= 0) {
            throw new \InvalidArgumentException('A price must be above zero.');
        }

        return gmp_strval($atomic);
    }
}
