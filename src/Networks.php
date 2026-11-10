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
    // Mainnets: Base, Solana and Arc (paying on them costs real money). Ethereum is here to be *read*: the
    // blacklist of its USDC and USDT is an eth_call, which costs no gas. Paying on
    // it is possible but a settlement there costs real gas. The testnets are for the demo.
    // Any other chain is registered by the site with add() (see "Add a blockchain" in docs/networks.md).
    public const array ALL = [
        'eip155:8453' => [
            'label' => 'Base (USDC)',
            // Circle's USDC on Base (developers.circle.com, checked 2026-11-05). The EIP-712 domain was read from
            // the contract on Base mainnet (name() "USD Coin", version() "2", chain id 8453) on 2026-11-05.
            'asset' => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
            'decimals' => 6,
            'extra' => ['name' => 'USD Coin', 'version' => '2'],
            'rpc' => 'https://mainnet.base.org',
            'explorer' => 'https://basescan.org/tx/',
        ],
        'eip155:1' => [
            'label' => 'Ethereum (USDC)',
            'asset' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
            'decimals' => 6,
            'extra' => ['name' => 'USD Coin', 'version' => '2'],
            'rpc' => 'https://ethereum-rpc.publicnode.com',
            'explorer' => 'https://etherscan.io/tx/',
        ],
        'eip155:84532' => [
            'label' => 'Base Sepolia (USDC)',
            'asset' => '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
            'decimals' => 6,
            'extra' => ['name' => 'USDC', 'version' => '2'],
            'rpc' => 'https://sepolia.base.org',
            'explorer' => 'https://sepolia.basescan.org/tx/',
        ],
        // Arc mainnet: asset, name() "USDC" and version() "2" read from the chain (rpc.mainnet.arc.io, chain id 0x13b2).
        'eip155:5042' => [
            'label' => 'Arc (USDC)',
            'asset' => '0x3600000000000000000000000000000000000000',
            'decimals' => 6,
            'extra' => ['name' => 'USDC', 'version' => '2'],
            'rpc' => 'https://rpc.mainnet.arc.io',
            'explorer' => 'https://explorer.arc.io/tx/',
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
        // Solana mainnet-beta: Circle's USDC mint (developers.circle.com, checked 2026-11-05).
        'solana:5eykt4UsFv8P8NJdTREpY1vzqKqZKvdp' => [
            'label' => 'Solana (USDC)',
            'asset' => 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
            'decimals' => 6,
            'extra' => [],
            'rpc' => 'https://api.mainnet-beta.solana.com',
            'explorer' => 'https://explorer.solana.com/tx/',
        ],
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
     * Adds a CEP-18 token on Casper (casper:casper or casper:casper-test).
     *
     * $packageHash is the token's contract package hash (64 hex, no prefix);
     * $name and $version its EIP-712 domain. Not built in: read them from the
     * token (wCSPR has 9 decimals), since a wrong one makes every signature invalid.
     *
     * @throws \InvalidArgumentException
     *                                   Not a Casper network or not a package hash
     */
    public static function addCasper(string $network, string $packageHash, string $name, string $version, int $decimals = CasperExact::DECIMALS, string $label = '', string $rpc = '', string $explorer = ''): string
    {
        if (!(new CasperExact())->supports($network) || !CasperExact::isPackageHash($packageHash) || '' === $name || '' === $version) {
            throw new \InvalidArgumentException('A Casper network is casper:casper or casper:casper-test, a package hash 64 lowercase hex, and the domain names a token.');
        }
        if ($decimals < 0 || $decimals > 38) {
            throw new \InvalidArgumentException("A token has 0 to 38 decimals: $decimals.");
        }
        if (isset(self::$added[$network]) && self::$added[$network]['asset'] !== $packageHash) {
            throw new \InvalidArgumentException("$network already has another token; call Networks::reset() first (a Budget cap would change meaning).");
        }
        self::$added[$network] = ['label' => $label ?: $network, 'asset' => $packageHash, 'decimals' => $decimals, 'extra' => ['name' => $name, 'version' => $version], 'rpc' => $rpc, 'explorer' => $explorer];

        return $network;
    }

    /**
     * Adds any EVM chain whose token implements EIP-3009.
     *
     * $name and $version are the token's EIP-712 domain: read them from the
     * contract (name(), version()), since a wrong one makes every signature
     * invalid. Whether the chain is a testnet is the caller's responsibility.
     *
     * $blacklist names the function TokenBlacklist asks about this token's
     * holders (a key of TokenBlacklist::FUNCTIONS, e.g. 'isBlacklisted' for
     * Circle's USDC). Null: the token is not asked.
     *
     * @throws \InvalidArgumentException
     *                                   A malformed chain id or token address, or an unknown blacklist function
     */
    public static function add(int $chainId, string $asset, string $name, string $version, int $decimals = 6, string $label = '', string $rpc = '', string $explorer = '', ?string $blacklist = null): string
    {
        if ($chainId <= 0 || !preg_match('/^0x[0-9a-fA-F]{40}$/', $asset)) {
            throw new \InvalidArgumentException('A chain id is positive and a token address is 0x followed by 40 hex characters.');
        }
        if (null !== $blacklist && !isset(TokenBlacklist::FUNCTIONS[$blacklist])) {
            throw new \InvalidArgumentException(\sprintf('Unknown blacklist function "%s": use %s.', $blacklist, implode(', ', array_keys(TokenBlacklist::FUNCTIONS))));
        }
        $network = "eip155:$chainId";
        self::$added[$network] = [
            'label' => $label ?: $network,
            'asset' => $asset,
            'decimals' => $decimals,
            'extra' => ['name' => $name, 'version' => $version],
            'rpc' => $rpc,
            'explorer' => $explorer,
            'blacklist' => $blacklist,
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
     * Fails when a network cannot be sold on: unknown, or verified by none of the schemes. For a deploy check,
     * so a mistyped network fails there and not on the first paid request.
     *
     * @param Scheme[] $schemes the schemes the seller's middleware is given
     *
     * @throws \InvalidArgumentException
     */
    public static function assertSupported(string $network, array $schemes): void
    {
        if (!self::has($network)) {
            throw new \InvalidArgumentException("\"$network\" is not a known network");
        }
        if ([] === array_filter($schemes, static fn (Scheme $s) => $s->supports($network))) {
            $registered = implode(', ', array_map(static fn (Scheme $s) => $s::class, $schemes)) ?: 'none';

            throw new \InvalidArgumentException("\"$network\" is supported by no registered scheme (registered: $registered).");
        }
    }

    /**
     * How long the buyer's signed payment stays valid, in seconds, by default.
     *
     * It must outlive the delivery: settling happens after it.
     */
    public const int TIMEOUT = 60;

    /**
     * One PaymentRequirements for a price in dollars.
     *
     * @param iterable<Scheme> $schemes your own schemes, for a chain family the library does not ship
     *
     * @throws \InvalidArgumentException
     *                                   Unknown network, a payTo that is no address there, or a price the token cannot represent
     */
    public static function requirements(string $network, string $usd, string $payTo, int $timeout = self::TIMEOUT, iterable $schemes = []): array
    {
        return self::offer($network, $usd, $payTo, $timeout, false, $schemes);
    }

    /**
     * One PaymentRequirements for an amount of a Casper token, in the token's own units.
     *
     * The wCSPR on Casper is not worth a dollar, so a price in dollars would be
     * charged in CSPR: `requirements()` refuses it, and a seller prices in the
     * token here ('1.5' is 1.5 CSPR). The offer is `upfront`: settle before the handler.
     *
     * @param iterable<Scheme> $schemes your own schemes, for a chain family the library does not ship
     *
     * @throws \InvalidArgumentException
     *                                   Not a Casper network, or an amount the token cannot represent
     */
    public static function requirementsInToken(string $network, string $amount, string $payTo, int $timeout = self::TIMEOUT, iterable $schemes = []): array
    {
        return self::offer($network, $amount, $payTo, $timeout, true, $schemes);
    }

    /**
     * @param iterable<Scheme> $schemes
     */
    private static function offer(string $network, string $usd, string $payTo, int $timeout, bool $inToken, iterable $schemes): array
    {
        $token = self::get($network);
        // A scheme whose offers are its own (Casper: priced in the token, settled upfront) shapes or refuses this one.
        $shaper = Schemes::find($network, $schemes, ShapesOffers::class);
        if (null === $shaper && $inToken) {
            throw new \InvalidArgumentException("requirementsInToken() is for Casper networks, not $network.");
        }
        $extra = $shaper?->offerExtra($network, $timeout, $inToken) ?? [];
        // The network's scheme says what an address is there (AddressForm); one that does not say is not checked.
        $form = Schemes::find($network, $schemes, AddressForm::class);
        if ('' === $payTo || (null !== $form && !$form->valid($payTo))) {
            throw new \InvalidArgumentException("Not a payTo address on $network: $payTo.");
        }

        return [
            'scheme' => 'exact',
            'network' => $network,
            'amount' => self::atomic($usd, $token['decimals']),
            'asset' => $token['asset'],
            'payTo' => null === $form ? $payTo : $form->spell($payTo),
            'maxTimeoutSeconds' => $timeout,
            'extra' => $token['extra'] + $extra,
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
