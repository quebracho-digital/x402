# quebracho/x402

[x402](https://www.x402.org/) payments for PHP: sign and verify payments, a
PSR-15 middleware for sellers and a PSR-18 client for buyers. No framework. MIT.

## Install

```bash
composer require quebracho/x402
```

Needs PHP 8.3+, `ext-gmp`, `ext-sodium`, any PSR-7/17 implementation (e.g.
`nyholm/psr7`) and, for buying, any PSR-18 client.

## Seller

```php
use Quebracho\X402\{EvmExact, FileClaims, Networks, X402Middleware};

// Price per request: a list of offers, or null when it is free.
$price = fn ($request) => [Networks::requirements('eip155:84532', '0.01', $payTo)];
$middleware = new X402Middleware([new EvmExact()], $price, $claim, $psr17, $psr17, $settle);
```

`$claim` makes each payment buy one delivery, even before it settles:
`(new FileClaims('/var/x402-claims'))(...)` on one host, or any atomic
`fn(string $key): bool` (DB unique key, Redis `SET NX`) on several.

Unpaid → `402` with `PAYMENT-REQUIRED`. Paid → verify offline, claim, run the
handler, settle only on 2xx, answer with `PAYMENT-RESPONSE`. `$settle` is a
facilitator callback; `Settlement::mock()` is mock mode, by name only: real
verification, nothing moves on chain, the receipt says `extensions.mock`.

Optional, by name, on both middlewares:

- `verify:` `fn($payment, $req): ?string`, an online check (`$facilitator->verify`)
  before the claim: an empty wallet runs nothing and takes no claim.
- `release:` `$claims->release(...)`: a payment that charged nothing (handler
  failed, settle failed with no transaction) is good again. A pending settle
  keeps its claim. `FileClaims::prune($seconds)` clears old claims.
- `resource:` the 402's `resource` (canonical URL, description, mimeType).
- `extra.paymentFlow = 'upfront'` in an offer settles **before** the handler
  runs. **Use it for any handler with an effect you cannot undo** (create an
  order, call a paid third-party API, issue a credential, publish, start a job):
  in the default flow the handler runs before settle, so if the settle then
  fails the buyer got the effect without paying. Once money moved, the receipt
  goes with whatever the handler answers; a handler that then fails records
  `failed_after_settle` so you can refund by hand. Default flow is for
  content-like output, where withholding the response is the whole delivery.
- A `FacilitatorException` answers 502 on HTTP (`facilitator_unavailable` on MCP).
  On settle the claim is kept: the facilitator may have sent the transaction.
- A tool result with `isError` is not charged and goes out as it is: a paid
  tool must not put what it sells in its errors.
- `$events` also receives a `PaymentOutcome` (settled, not_settled, failed,
  rejected) per paid request; a listener that throws cannot undo a sale.

### Facilitators

`$settle = fn ($payment, $req) => $facilitator->settle($payment, $req);` with
any `Facilitator` (all PSR-18 + PSR-17):

- `new HttpFacilitator($http, $psr17, $psr17, 'https://x402.org/facilitator')`:
  any x402 facilitator by URL (`/verify`, `/settle`, `/supported`, `feePayer()`
  for Solana). A settle counts only when consistent: 2xx, `success: true`, a
  transaction, our network, the payer who signed. Verified on Base Sepolia.
- Circle: `HttpFacilitator::CIRCLE` with an API key (`$bearer`), or keyless
  with the payTo key (`$seller` signs the seller proof). A pending settle is
  polled at `/status`.
- `new ChainFacilitator(new Rpc(...), $gasWallet, 'eip155:5042002', $lock)`:
  no third party; simulates with `eth_call`, sends `transferWithAuthorization`
  from a gas wallet, waits for the receipt (`settlement_pending` keeps the hash).

Configure the PSR-18 client not to follow redirects, so the signed payment
reaches only the facilitator you named.

### MCP

For an MCP server over Streamable HTTP, `X402McpMiddleware` follows the
official [x402 MCP transport](https://github.com/x402-foundation/x402/blob/main/specs/transports-v2/mcp.md):
an unpaid `tools/call` gets `isError` + PaymentRequired, the payment comes in
`params._meta["x402/payment"]`, the receipt in `result._meta["x402/payment-response"]`.

```php
$price = fn (array $params) => $params['name'] === 'wisdom' ? [Networks::requirements(...)] : null;
$middleware = new X402McpMiddleware([new EvmExact()], $price, $claim, $psr17, $psr17, $settle);
```

## Buyer

```php
use Quebracho\X402\{Budget, EvmExact, PayingHttpClient, Signer};

$client = new PayingHttpClient(
    $psr18,
    [new EvmExact(new Signer($testnetKey))],
    Budget::usd('eip155:84532', '0.05'),
);
```

For MCP, `new PayingMcpClient($psr18, $schemes, $budget, $psr17Streams, $onPaid)`
goes under the MCP client's HTTP transport instead: it pays a PaymentRequired
tool result once, resending the call with `params._meta["x402/payment"]`, and
reads the receipt from `result._meta["x402/payment-response"]`.

`PayingHttpClient` pays a 402 once and retries the same request. Wrap a client
that does not follow redirects, or another host gets the signed payment. The budget is a per-network cap,
in memory, spent when it signs (a signed authorization is money the seller
can settle) and never refunded on the seller's word. Both buyers sign only
known tokens (`Networks`), integer amounts and validities up to 10 minutes.
With `Budget::usd($network, $usd, $used)`, where `$used` reads the token's
`authorizationState` on chain, a signature the seller never settled frees
its amount once it expires unused.

`onRefused: fn(array $req, string $reason)` on both buyers says why an offer
was not paid (`no_scheme`, `not_allowed`, or the screening's reason).
PSR-18 has no per-request options: give the wrapped client a timeout longer
than the offers' `maxTimeoutSeconds` plus the seller's settle (e.g. 120 s), or
a buyer that hangs up early may be charged for an answer it never reads.

## Networks

Built in: the demo testnets (Base Sepolia, Arc testnet, Ethereum Sepolia,
Avalanche Fuji, Polygon Amoy) and **Ethereum mainnet**. The library only *reads*
Ethereum: it asks the blacklist of its USDC and USDT with an `eth_call`, which
costs no gas. Settling a payment there costs real gas, often more than a small
price, so charge on Ethereum only for amounts that justify it. Any other chain
is yours to add.

### Add a blockchain

Any EVM chain whose token implements EIP-3009 (`transferWithAuthorization`).
Three steps; the third is what keeps a frozen address from costing you a tool run.

**1. Register the network**, once, when the site boots:

```php
Networks::add(
    chainId: 8453,
    asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', // the token contract
    name: 'USD Coin',   // the token's EIP-712 domain: read name() and version()
    version: '2',       // from the contract; a wrong one makes every signature invalid
    label: 'Base (USDC)',
    rpc: 'https://mainnet.base.org',          // used for the blacklist and for sweeping
    explorer: 'https://basescan.org/tx/',
);
```

**2. Tell the blacklist which function to ask.** Each token names its own, and
none is assumed: an asset that is not listed is **not asked** (it passes). The
functions the library knows, all `(address) returns (bool)`:

| Token | Function | Selector |
|---|---|---|
| USDC (Circle) | `isBlacklisted(address)` | `0xfe575a87` |
| USDT (Tether), legacy | `isBlackListed(address)` or `getBlackListStatus(address)` | `0xe47d6060`, `0x59bf1abe` |
| USDT0 | `isBlocked(address)` | `0xfbac3951` (not active, see `TokenBlacklist::TOKENS`) |

`TokenBlacklist::TOKENS` already lists the native USDC of these mainnets, each
read from a public RPC on 2026-11-03 (they are asked once you register the network):

| Chain | `eip155:` id | USDC contract |
|---|---|---|
| Ethereum (built in) | 1 | `0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48` |
| Base | 8453 | `0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913` |
| Polygon | 137 | `0x3c499c542cEF5E3811e1192ce70d8cC03d5c3359` |
| Avalanche C-Chain | 43114 | `0xB97EF9Ef8734C71904D8002F8b6Bc66Dd9c48a6E` |
| Optimism | 10 | `0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85` |
| Arbitrum One | 42161 | `0xaf88d065e77c8cC2239327C5EDb3A432268e5831` |
| Arc | 5042 | `0x3600000000000000000000000000000000000000` |

For any other token, name its function without editing the library:

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

## Address screening

Pass any PSR-14 dispatcher (Symfony's, League's, …) as `$events` to a
middleware or a paying client, and it dispatches an `AddressCheck`:

- **seller**: on the payer, after the signature verified and before the
  payment is claimed or the handler runs;
- **buyer**: on each offer's `payTo`, before signing it (and before the budget
  is spent).

A slow listener (an API) calls `$check->defer(fn () => ?string)` instead:
the seller reads it just before settling, so the lookup overlaps the
handler, and withholds the output on a late rejection.

A listener calls `$check->reject('reason')` to block; the seller answers a
PaymentRequired with that reason, the buyer skips the offer. A listener that
throws blocks too (`address_check_failed`). No dispatcher, no screening. The
lists themselves (OFAC, your own, an API) are the listener's business.

```php
$dispatcher->addListener(AddressCheck::class, function (AddressCheck $check) use ($denied) {
    if (in_array(strtolower($check->address), $denied, true)) {
        $check->reject('address_denied');
    }
});
```

## Schemes

`Scheme` (`supports`, `pay`, `verify`, `payer`) is one chain family; experimental until
1.0. `EvmExact` is the stable one. `SolanaExact` (`solana:*`, Solana devnet
USDC built in) pays with a `SolanaKey` and verifies offline: one
TransferChecked to the seller's token account, the fee payer in no
instruction, compute caps of the spec, the buyer's signature. Smart wallets
and lookup tables are refused. `unsignedTransaction()` hands the transaction
to a browser wallet. The offer needs `extra.feePayer` (`HttpFacilitator::feePayer`).
`NearExact` (`near:testnet`) is experimental, see [docs/near.md](docs/near.md).
A NEAR seller that screens payers passes `keyOwner: NearExact::keyOwner($rpc)`
(the node's `view_access_key`): without it the payer is unproven and screening
rejects it.
`CasperExact` (`casper:casper`, `casper:casper-test`) is experimental, see
[docs/casper.md](docs/casper.md): a CEP-18 authorization signed as Casper
EIP-712, settled by `HttpFacilitator::CSPR_CLOUD`; register the token with
`Networks::addCasper`.

## Protections, layer by layer

Every line is backed by a test in `tests/` (`vendor/bin/phpunit tests`). The
reasons are in the repository's [DECISIONS.md](../../DECISIONS.md); the
attack-by-attack list is in the [repository README](../../README.md#attacks-it-resists).

**Verify is not paid.** The one rule the rest follows from (see Wang et al.,
*x402 facilitators*, USENIX Security 2026): a payment that verified has not
moved money. Nothing here treats `verify` as a receipt. Only a settle that came
back consistent (2xx, `success: true`, a transaction, our network, the payer
who signed) is "paid"; anything else withholds the output.

*Replay and "free shopping"*
- **One signature, one delivery.** The payment is claimed atomically before the
  handler runs (network + payer + EIP-3009 nonce on EVM; the signed message on
  Solana; sender + key + nonce on NEAR). The key follows the network family,
  not the shape of the payload, and EVM values must be canonical (one spelling
  per address, nonce, number), so `0xAB…` and `0xab…` cannot make two claims.
- **A replay never runs the handler.** The official middlewares run it and then
  find the nonce spent; here the copy is refused first, so a replay costs the
  seller nothing.
- **A claim is freed only when nothing can have been charged** (the handler
  delivered nothing, or the facilitator clearly refused). It is kept on
  `settlement_pending`, `facilitator_unavailable`, `unexpected_settle_error`
  and `invalid_settle_response`: the transaction may still land, and a freed
  claim would sell the same payment twice.
- **Mock mode by name.** `$settle` is mandatory; `Settlement::mock()` verifies
  for real, moves nothing and stamps `extensions.mock` on the receipt.

*Requirements binding and authenticity (offline, before anything is spent)*
- The offer is chosen from the seller's own list, never from the buyer's copy;
  the buyer's echo of asset, payTo and amount must match it.
- EVM: scheme, network, version, recipient, exact value, `validAfter`,
  `validBefore` (with 6 s of slack) and the EIP-712 signature (recovered
  signer must be `from`; high-s malleated signatures refused, EIP-2).
- The signature's validity is capped by the offer's `maxTimeoutSeconds`
  (+60 s skew), which bounds replay windows and claim storage.
- An offer that charges nothing (amount zero or not a plain positive integer)
  is refused. `Networks::requirements` already refuses to build one.
- Malformed payloads get a rejection reason, never a 5xx.

*What the sponsored gas can do (ChainFacilitator)*
- It builds the transaction itself: a `transferWithAuthorization` to the
  *offer's* token contract, calldata assembled field by field from validated
  values. Client-supplied calldata is never forwarded; there is no generic relay.
- **No smart-wallet execution.** Signatures must be exactly 65 bytes
  (r ‖ s ‖ v). ERC-1271 and ERC-6492 (counterfactual deployment, factory calls)
  are refused on purpose: they bring executable bytes and unbounded gas into
  verification. Solana smart wallets and lookup tables are refused likewise.
- Simulates with `eth_call` first and estimates gas (+20 %); `maxGasCost`
  aborts anything dearer; one lock per network serialises the gas wallet's
  nonce; the transaction hash is known before it is sent, so a send that times
  out still leaves something to reconcile; the receipt must have `status 0x1`.
- Refuses what would revert anyway: a payment that is not for our payTo,
  amount or network, an expired one, a frozen payer or payTo (see Address
  screening), a nonce the chain already spent (online `verify`).

*Delivery model and who bears the risk*
- Default flow: verify offline → (screen, online verify) → claim → handler →
  settle on 2xx. The output is withheld if settle fails, and **the handler has
  already run**: right for content (JSON, text, files), not for irreversible
  effects. Use `paymentFlow = 'upfront'` for those, see below.
- A handler that fails (non-2xx, `isError`, exception) is not charged and its
  claim is freed.
- A late rejection from a slow address check withholds the output as well.

*Buyer*
- Signs only known tokens and EIP-712 domains, positive integer amounts within a
  per-network cap, a canonical `payTo`, and validity up to 10 minutes.
- The budget is spent when it signs and never refunded on the seller's word.
- The wrapped PSR-18 client must not follow redirects, or another host gets the
  signed payment.

*Both sides*
- Payer and `payTo` screening through a PSR-14 event; a list or API that
  cannot answer fails closed. `TokenBlacklist` asks USDC's (and Tether's
  legacy USDT's) on-chain blacklist before a signature is spent.

*Known limits*
- Pending or silent settles are logged, not reconciled automatically.
- `FileClaims` is single-host; several hosts need an atomic store.
- A third-party HTTP facilitator may accept at `/verify` and still fail at
  `/settle` (state changed in between); the gas is then its cost, not ours.
  Use `ChainFacilitator` to carry that yourself.
- Concentration: one facilitator URL is one point of failure. The library takes
  any `Facilitator`, so a fallback is a few lines of the caller's.

## Frameworks without PSR-7

Drupal and Symfony speak HttpFoundation: bridge both ways with
`symfony/psr-http-message-bridge` and a PSR-17 factory. That buffers the body,
so streamed and file responses lose their laziness; fine for paid JSON or
Markdown. The steps are plain calls underneath (`Scheme::verify`,
`Settlement::key`, `Settlement::run`, a `Facilitator`), so a framework can
charge without PSR-15 too.

## Tests

```bash
vendor/bin/phpunit tests   # no network
```

See [NOTICE](NOTICE) for the origin of some classes.
