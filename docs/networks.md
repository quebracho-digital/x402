# Networks

Built in: the demo testnets (Base Sepolia, Arc testnet, Ethereum Sepolia,
Avalanche Fuji, Polygon Amoy, Solana devnet), **Arc mainnet** (`eip155:5042`)
and **Ethereum mainnet**. Ethereum is there to be *read*: the library asks the blacklist of its USDC and USDT with an `eth_call`, which
costs no gas. Settling a payment there costs real gas, often more than a small
price, so charge on Ethereum only for amounts that justify it. Any other chain
is yours to add.

## Add a blockchain

Any EVM chain whose token implements EIP-3009 (`transferWithAuthorization`).
Three steps; the third is what keeps a frozen address from costing you a tool run.

**1. Register the network**, once, when the site boots (`blacklist:` is step 2):

```php
Networks::add(
    chainId: 8453,
    asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', // the token contract
    name: 'USD Coin',   // the token's EIP-712 domain: read name() and version()
    version: '2',       // from the contract; a wrong one makes every signature invalid
    label: 'Base (USDC)',
    rpc: 'https://mainnet.base.org',          // used for the blacklist and for sweeping
    explorer: 'https://basescan.org/tx/',
    blacklist: 'isBlacklisted',              // the function asked about this token's holders (step 2)
);
```

**2. Name the blacklist function**, with `blacklist:` in step 1 (a key of
`TokenBlacklist::FUNCTIONS`). Each token names its own, and none is assumed: a
network registered with `blacklist: null` (the default) is **not asked** (it
passes). An unknown name throws at `add()`. The functions the library knows,
all `(address) returns (bool)`:

| Token | Function | Selector |
|---|---|---|
| USDC (Circle) | `isBlacklisted(address)` | `0xfe575a87` |
| USDT (Tether), legacy | `isBlackListed(address)` or `getBlackListStatus(address)` | `0xe47d6060`, `0x59bf1abe` |
| USDT0 | `isBlocked(address)` | `0xfbac3951` (not active, see `TokenBlacklist::TOKENS`) |

`TokenBlacklist::TOKENS` already lists the native USDC of these mainnets, each
read from a public RPC on 2026-11-03 (Ethereum and Arc are built in; the others are
asked once you register the network):

| Chain | `eip155:` id | USDC contract |
|---|---|---|
| Ethereum (built in) | 1 | `0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48` |
| Base | 8453 | `0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913` |
| Polygon | 137 | `0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359` |
| Avalanche C-Chain | 43114 | `0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E` |
| Optimism | 10 | `0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85` |
| Arbitrum One | 42161 | `0xaf88d065e77c8cC2239327C5EDb3A432268e5831` |
| Arc (built in) | 5042 | `0x3600000000000000000000000000000000000000` |

`$tokens` is for a token that is already registered (one added before
`blacklist:` existed), or to override the function of one:

```php
new TokenBlacklist($client, $requests, $streams, tokens: [
    'eip155:4202|0xYourToken' => 'isBlacklisted', // network|asset => function (a key of FUNCTIONS)
]);
```

**3. Check it against the real contract** before you trust it. The function must
answer, and a made-up selector must revert (a token that lacks the function makes
every check fail closed):

```bash
RPC=https://mainnet.base.org; ASSET=0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913
ARG=$(printf '%064x' 57005)   # the address 0x…dead, padded to 32 bytes
call() { curl -s "$RPC" -H 'content-type: application/json' \
  -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"eth_call\",\"params\":[{\"to\":\"$ASSET\",\"data\":\"$1\"},\"latest\"]}"; }
call 0x06fdde03          # name()    -> "USD Coin"
call 0x54fd4d50          # version() -> "2"
call 0xfe575a87$ARG      # isBlacklisted(0x…dead) -> 0x00…00 (not frozen)
call 0xdeadbeef$ARG      # made-up selector -> an error: the contract has no such function
```

A node that cannot answer rejects the payment (fail closed) unless you build the
listener with `failOpen: true`. Also check that your facilitator supports the
network, or settle yourself with `ChainFacilitator` and pay the gas.
