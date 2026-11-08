# quebracho/x402

[x402](https://www.x402.org/) payments for PHP: sign and verify payments, a
PSR-15 middleware for sellers and a PSR-18 client for buyers. No framework. MIT.

## Install

```bash
composer require quebracho/x402
```

Needs PHP 8.3+, `ext-gmp`, `ext-sodium`, any PSR-7/17 implementation (e.g.
`nyholm/psr7`) and, for buying, any PSR-18 client.

Upgrading from 0.3: read [UPGRADE.md](UPGRADE.md) (OFAC screening is now on by default).

## Seller

```php
use Quebracho\X402\{EvmExact, FileClaims, Networks, X402Middleware};

// Price per request: a list of offers, or null when it is free.
$price = fn ($request) => [Networks::requirements('eip155:84532', '0.01', $payTo)];
$middleware = new X402Middleware([new EvmExact()], $price, $claim, $psr17, $psr17, $settle);
```

`$claim` makes each payment buy one delivery, even before it settles:
`(new FileClaims('/var/x402-claims'))(...)` on one host, or any atomic
`fn(string $key): bool` (DB unique key, Redis `SET NX`) on several. To keep
more than the claim (the stored response, pending settles), pass a ledger: see
[Payment ledger](#payment-ledger).

Unpaid → `402` with `PAYMENT-REQUIRED`. Paid → verify offline, claim, run the
handler, settle only on 2xx, answer with `PAYMENT-RESPONSE`. `$settle` is a
facilitator callback; `Settlement::mock()` is mock mode, by name only: real
verification, nothing moves on chain, the receipt says `extensions.mock`.

Optional, by name, on both middlewares:

- `verify:` `fn($payment, $req): ?string`, an online check (`$facilitator->verify`)
  before the claim: an empty wallet runs nothing and takes no claim. Return
  `NULL` when the payment is good, or a non-empty reason string. Any other
  return (`''`, `false`, an array) or any exception refuses as
  `unexpected_verify_error`; a `FacilitatorException` stays `facilitator_unavailable` (502).
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
  rejected, failed_after_settle) per paid request; its `error` holds the
  exception behind a reason (verify or settle that threw, facilitator down,
  a ledger that refused a write), for logs only. A listener that throws cannot
  undo a sale. The other events are in [Events and logs](#events-and-logs).

### Payment ledger

`PaymentLedger` keeps each payment's state across requests and processes, by
name on both middlewares. It replaces `$claim` and `$release` (the signature
still takes `$claim`; it is not used then):

```php
use Quebracho\X402\{EvmExact, FileLedger, X402Middleware};

$middleware = new X402Middleware([new EvmExact()], $price, $claim, $psr17, $psr17, $settle,
    ledger: new FileLedger('/var/x402-ledger'),   // one host; several hosts need a shared store
    lookup: $lookup,                              // optional, see "pending" below
    sensitive: $sensitive,                        // optional: fn(request|params): bool, keep the state, never the body
);
```

A request `sensitive` marks (or one whose check throws) is delivered, but its
body is never stored: a retry after it settled gets `already_settled`, not a
replay.

`claim($key, $ttl)` must be atomic (of N concurrent calls exactly one returns
TRUE); `get`, `put` and `release` complete the state part of the interface.
`open`, `openKeys` and `close` keep an index of open payments: every
`settling` or `pending` state is opened (with the network, payer, nonce, payTo,
amount, validBefore and any transaction or `paymentId`, never a signature or a
body) for three days, and `settled` or `delivered` closes it, so a reconciler
can walk them (`openKeys($limit)`) and ask the chain. `FileLedger` is one
host: one JSON file per key, plus one `.open` file per open entry. A store that cannot answer throws
`LedgerUnavailableException`, and nothing is charged: before the handler runs,
HTTP answers `503 ledger_unavailable` and MCP answers the PaymentRequired with
`error: ledger_unavailable` in-band.

- **States.** `claimed` (in progress: a retry gets `409 in_progress`);
  `settled` (charged, no response kept: `409 already_settled`); `delivered`
  (charged, the 2xx response kept: a retry gets it again, not run again, with
  the receipt); `pending` (may have charged, not confirmed: see below).
- **Written before the money or the handler moves (write-ahead).**
  `settling` is stored before a settle is sent. `handling` is stored before an
  upfront handler runs, and it is closed by `delivered`, or by `settled` with
  `handler_ran: false` when the handler answered non-2xx or threw, or on MCP
  answered `isError` or a JSON-RPC error (a retry runs it again, as before). If the store refuses the `settling` write, nothing is
  sent: `503 ledger_unavailable`. Refused on the `handling` write, the handler
  does not run.
- **A retry that finds an open intention.** `settling` without a transaction or
  `paymentId` in its receipt: `409 settlement_unknown` (no settle, no handler).
  With one, the lookup decides: confirmed settles it (an upfront handler then
  runs once), refuted frees it, unknown answers `409 settlement_unknown`.
  `handling` without a kept response: `409 needs_review` (MCP: `isError` with
  `needs_review`). The handler is never run again by the middleware: a person
  decides. A `handling` with a kept response is replayed.
- **Residual window.** A crash while a settle request is in flight, or a store
  failure when the outcome is written, leaves `settling` with no reference (the
  transaction or `paymentId` exists only once the facilitator answers). It
  answers `409 settlement_unknown` until its TTL frees the claim. After that the
  retry goes through verify again: if the settle did happen, the token rejects
  the spent nonce, so nothing is charged twice, but the buyer paid and got no
  response, and nothing tells the seller on its own. `ChainFacilitator` closes
  it: its transaction is stored in `settling` before it is sent (pass
  `$chain->settle(...)` as `$settle`), and a store that refuses that write sends
  nothing (`503 ledger_unavailable`).
- **What is kept.** Only a 2xx response of at most 1 MiB, base64 in the state.
  Its TTL is `min(600, validBefore − now)` (24 h for a `settling` or `pending`
  state with a transaction); each state expires with it.
- **The store is read only after the signature verifies offline**: the nonce
  is public, so anyone can copy a payment, and a forged copy costs no read.
- **Pending.** A settle that may have charged and is not confirmed (its claim
  kept) is asked of `lookup(array $state): ?array` when the buyer retries: a
  receipt with `success: true` settles it (the response is then replayed), one
  with `success: false` frees it (the retry runs as new), `NULL` (or a lookup
  that throws) keeps it pending and answers `409 settlement_pending`.
- **A failed store after a settle does not become an error.** If a
  `LedgerUnavailableException` comes when the outcome is stored after a
  successful settle, the response still goes out: the money has moved. The claim
  stays until its TTL, so the buyer cannot pay twice. A `PaymentFault` with op
  `record` says so: a person (or the reconciler) looks.

Ready-made pieces, all PSR-18 + PSR-17:

- `RedisLedger('redis://host:6379')` (ext-redis): one ledger for several hosts.
  Claims are `SET NX EX`, so one payment is delivered once across hosts. A Redis
  that does not answer is `ledger_unavailable`; never fall back to `FileLedger`
  then. Run Redis with `maxmemory-policy noeviction` and `appendonly yes`.
- `ReceiptLookup($paymentStatus, $http, $psr17, $psr17)` is a `lookup`: an EVM
  transaction is confirmed by its receipt and the asset's `AuthorizationUsed`
  log for this payer and nonce; a `paymentId` by the facilitator's `/status`
  (`PaymentStatus`: Circle's; `null` asks only the chain). Any failure is
  `NULL`, still pending.
- `Reconciler($ledger, $http, $psr17, $psr17)->reconcile($limit)`, from cron
  every few minutes: walks the open entries and asks the chain
  (`authorizationState`, then the `AuthorizationUsed` log). An authorization used
  is written `settled`; one unused and expired is freed. It never charges nor
  runs a handler. It returns one row per entry; log the `needs_review` and
  `rpc_error` ones, a person resolves those. EVM (EIP-3009) only.

### Events and logs

Every event is PSR-14 and only reports: a listener that throws changes no
answer. Pass the dispatcher as `$events`.

| Event | Side | When |
|---|---|---|
| `PaymentIntent` | seller | before a settle is sent or an upfront handler runs (a crash after it still leaves a line) |
| `PaymentOutcome` | seller | once per paid request: settled, not_settled, failed, rejected, failed_after_settle |
| `PaymentFault` | seller | the ledger could not do something and the sale went on: `record` (the state after a settle was lost: needs a person), `intend`, `release`, `release_run`, `close`, `lookup` |
| `AddressRejected` | both | the screening refused an address; `error` when a list threw |
| `ScreeningSkipped` | both | a list built with `failOpen: true` could not answer, so the address passed it unchecked |
| `PurchaseIntent` | buyer | a payment is signed and about to be sent; its `key` is the seller's ledger key |
| `PurchaseOutcome` | buyer | per offer: paid, refused, released (the signature failed), unsettled |
| `BudgetReclaim` | buyer | `reclaim()` asked the chain about an expired signature: freed, used, unknown |

None of them holds a signature or a response body. `error` fields may hold
internals: logs only.

`PaymentLog($psr3Logger)` writes one line per event, at a level that says how
urgent it is (`failed_after_settle` and a lost `record` are errors).
`PaymentLog::events()` maps each event class to its method, to subscribe them
all at once. `PaymentRecord($dir)`, a `PaymentOutcome` listener, appends one
JSON line per sale or failed settlement to `$dir/<YYYY-MM>.jsonl`, files kept
and never rotated: what moved money, or should have.

```php
$log = new PaymentLog($logger);
foreach (PaymentLog::events() as $event => $method) {
    $dispatcher->addListener($event, [$log, $method]);
}
$dispatcher->addListener(PaymentOutcome::class, new PaymentRecord('/var/lib/x402/records', $logger));
```

### Facilitators

`$settle = fn ($payment, $req) => $facilitator->settle($payment, $req);` with
any `Facilitator` (all PSR-18 + PSR-17):

- `new HttpFacilitator($http, $psr17, $psr17, 'https://x402.org/facilitator', $bearer)`:
  the generic client, any x402 facilitator by URL with an optional bearer key
  (`verify`, `settle`, `supported`, `feePayer()` for Solana). A settle counts only when consistent: 2xx, `success: true`, a
  transaction, our network, the payer who signed. Verified on Base Sepolia.
  The crash window between the send and the answer stays open with HTTP
  facilitators (this one and Circle's): they give no reference before they answer.
- Circle: `new CircleFacilitator($http, $psr17, $psr17, $bearer, $seller)`
  (URL `CircleFacilitator::URL`), with an API key (`$bearer`), or keyless
  with the payTo key (`$seller` signs the seller proof). A pending settle is
  polled at `/status` by its `paymentId`; only this one has that route
  (`PaymentStatus`). Still pending after polling, it answers
  `settlement_pending` with that `paymentId`, which the ledger's lookup uses.

  Migrating from 0.1.x: `HttpFacilitator::CIRCLE` is a deprecated alias of
  `CircleFacilitator::URL`, kept so old code still compiles. Through
  `HttpFacilitator` that URL is verify and settle only: keyless Circle needs the
  seller proof and the `/status` poll, which only `CircleFacilitator` sends.
  Move those callers to `new CircleFacilitator(...)`.

  Migrating from 0.2.x: `HttpFacilitator` keeps the v0.2.1 argument order
  (`$bearer`, `$seller`, `$pollDelaySeconds`, `$pollAttempts`) as a shim, and the
  seller proof headers and the `/status` poll keep working through it. The new
  optional `$headers` hook comes last. Passing `$seller` raises
  `E_USER_DEPRECATED`; `$seller`, `$pollDelaySeconds` and `$pollAttempts` are
  removed in the next major version (1.0). Use `new CircleFacilitator(...)`
  instead, which sends the same proof and polls the same way.
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

Optional hooks, each off by default (a tool that does not use them is sold
exactly as without them):

- `validate: fn(array $params): ?string` runs before any payment is read; a
  reason is a JSON-RPC `-32602` error and nothing is claimed, settled or run.
  `$price` returning NULL means *free*, so it cannot be the validation.
- `payer: fn(array $params, string $payer): ?string` runs once the signature
  proved who pays, before the claim: the reason it returns is the
  PaymentRequired's `error` (your own code; for example, only the payer who
  started a job may buy its result). A scheme that cannot name the payer is
  `invalid_payload`.
- `duration: fn(array $params): int` is the tool's worst case in seconds from
  the claim until the settle is sent. An EVM signature whose `validBefore`
  leaves less than that plus `EvmExact::SLACK` (6 s), on `EvmExact`'s clock, is
  refused right after the offline verify (no facilitator round trip) and again
  just before the claim, once the online verify took its time
  (`invalid_exact_evm_payload_authorization_valid_before`,
  the spec's code: here "too short for this tool"); a scheme
  without `validBefore` is refused (`unsupported_scheme`) when a duration is
  declared. Give that tool a longer `maxTimeoutSeconds`
  (`Networks::requirements(..., $timeout)`).

Upfront over MCP: once the money moved the receipt always goes back, in
`result._meta["x402/payment-response"]`, or in
`error.data["x402/payment-response"]` when the tool answered with a JSON-RPC
error (a scalar `data` is kept under `data.data`).

### Job mode

A work that takes minutes (a model, a render) is sold in two payments: a
`<tool>_start` call takes a deposit upfront and queues the work, a free
`<tool>_status` says when it is ready, and a `<tool>_result` call buys the
result in the default flow (settled once it is delivered). The protocol is in
[`docs/job-mode.md`](https://github.com/quebracho-digital/quebracho-x402-mcp/blob/main/docs/job-mode.md).
The library holds its state machine and the pieces that do not depend on a
framework, under `Quebracho\X402\Job`:

| Piece | What it is |
|---|---|
| `Job` | one job, immutable: `settling > queued > running > ready > delivering > delivered`, or `failed_ours` (our fault, by a closed list: the result is then free). Each transition is checked against `Job::TRANSITIONS`; the buyer's input is dropped once the work ran |
| `JobStore` | where jobs live: atomic, serialised writes; `create` is idempotent on the deposit's `Settlement::key`. `FileJobStore` is one host (flock, rename, files 0600) |
| `JobWork` | the work one `_start` tool sells: `check()` before any payment is read, `refuse()` before the deposit settles, `run()` |
| `JobRunner` | runs a queued job once the `_start` response went out (after the response, or from a queue worker) |
| `JobStatus` | what `_status` answers, the same for a stranger's job as for none |
| `JobPayments` | how a job's payment ended when its request is gone: the ledger first, then `authorizationState` |
| `JobReconciler`, `JobJanitor` | from cron: move on the jobs a lost answer left waiting, give up on stale work, forget results and buyer text after their TTLs |

What stays with your app: the MCP tools themselves and the middleware's hooks
that tie them to these pieces (`validate` runs `JobWork::check`, `payer`
refuses a `_result` from anyone but the deposit's payer, `$settle` writes the
`settling` row before the deposit moves and queues it after), plus a
concurrency limit. The demo app in the same repository has a complete,
commented implementation (`app/src/X402/JobDesk.php`).

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
known tokens (`Networks`), integer amounts and validities up to 10 minutes
(`Budget::fromUsd(..., maxTimeout: 170)` lowers that: never sign a validity
longer than your HTTP client waits).
With `Budget::usd($network, $usd, $used)`, where `$used` reads the token's
`authorizationState` on chain (`new AuthorizationState($http, $psr17, $psr17)`,
with a short timeout), a signature the seller never settled frees its amount
once it expires unused.

Concurrent payments in one process (Fibers, ReactPHP, Amp) share the same
`Budget` object, and the cap holds across them: `reserve()` checks the offer
and counts it in one step before signing, and `release()` gives it back if the
payment never left (a failed signature). The budget lives in that one process
only: separate processes, workers or hosts each have their own cap, so a
shared limit needs the app to store the spent totals itself.

`onRefused: fn(array $req, string $reason)` on both buyers says why an offer
was not paid (`no_scheme`, `not_allowed`, or the screening's reason). With
`$events`, both also report `PurchaseIntent`, `PurchaseOutcome` and
`BudgetReclaim` ([Events and logs](#events-and-logs)).

**Pay only the sellers you know.** `PayeeAllowlist`, an `AddressCheck`
listener, refuses every `payTo` not listed (`payto_not_allowed`); an empty list
pays nobody. Recommended for an agent: a listing it was shown (or a prompt that
reached it) cannot name its own wallet. OFAC still runs first.

```php
$dispatcher->addListener(AddressCheck::class, PayeeAllowlist::fromCsv(getenv('X402_PAYTO_ALLOW')));
```
PSR-18 has no per-request options: give the wrapped client a timeout longer
than the offers' `maxTimeoutSeconds` plus the seller's settle (e.g. 120 s), or
a buyer that hangs up early may be charged for an answer it never reads.

## Networks

Built in: the demo testnets (Base Sepolia, Arc testnet, Ethereum Sepolia,
Avalanche Fuji, Polygon Amoy, Solana devnet), **Arc mainnet** (`eip155:5042`)
and **Ethereum mainnet**. Ethereum is there to be *read*: the library asks the blacklist of its USDC and USDT with an `eth_call`, which
costs no gas. Settling a payment there costs real gas, often more than a small
price, so charge on Ethereum only for amounts that justify it. Any other chain
is yours to add.

### Add a blockchain

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

## Address screening

**OFAC's SDN list is on by default.** Every middleware and paying client asks
`OfacSdn` about the address first, with or without a dispatcher: a listed
payer is refused (`address_sanctioned`) before anything is claimed or run, and
a listed `payTo` is never signed for. The list comes from OFAC's own
[Sanctions List Service](https://ofac.treasury.gov/sanctions-list-service)
(`SDN.CSV`), every "Digital Currency Address" in it, every currency. A `0x…`
address is matched in any case on every EVM network; other forms exactly.

The library ships a copy of the list (`resources/ofac-sdn.json`), so the
check works offline from the first request. We refresh that copy now and then
(`bin/update-ofac.php`, before each release), but **keeping the list fresh is
your app's job**: OFAC updates it a few times a week. Run a `ListSync` from
your own cron, with your own PSR-18 client, and pass its file; the newer of
your copy and the shipped one is used.

```php
// daily, from your cron: downloads SDN.CSV, replaces the file only if the parse looks right
(new OfacSdnSync($psr18Client, $requestFactory))->sync('/var/lib/x402/ofac-sdn.json');

// every middleware and paying client takes it as `ofac:`
$ofac = new OfacSdn('/var/lib/x402/ofac-sdn.json');
new X402McpMiddleware($schemes, $price, $claim, $responses, $streams, $settle, ofac: $ofac);
new PayingMcpClient($client, $schemes, $budget, $streams, ofac: $ofac);
```

A snapshot that cannot be read refuses every address (fails closed). This
screens the addresses OFAC publishes, nothing more: names, countries or other
lists you are bound by go in your own listeners, below. Not legal advice.

**Turning it off** takes a name: pass `ofac: OfacSdn::off()`. Your seller then
takes money from, and your buyer pays, addresses OFAC names; whoever turns it
off answers for that. Leaving `ofac:` out never turns it off.

**More lists.** Each is an `AddressCheck` listener (below), asked only about
addresses OFAC's list passed:

| Listener | Source | Refuses with |
|---|---|---|
| `AddressList([$files], $reason)` | your own files, or a list another site publishes, synced with `AddressList::sync($http, $requests, $url, $file)` | `address_denied` (or `$reason`) |
| `TokenBlacklist` | the token contract's own blacklist (USDC, USDT), one `eth_call` | `address_blacklisted` |
| `ChainalysisOracle` | Chainalysis' on-chain sanctions oracle, one free `eth_call` on Ethereum, EVM addresses only | `address_sanctioned` |
| `ChainalysisApi($http, $requests, $key)` | Chainalysis' free sanctions API, any address form | `address_sanctioned` |

The oracle and the API are Chainalysis' copies of the sanctions lists, a second
opinion that may lag OFAC's either way. Each fails closed when its source does
not answer (`failOpen: true` passes instead, and reports `ScreeningSkipped`),
and a list file never synced refuses everyone until it is. A 4xx from the API
(a bad key) is never failed open.

The list format is plain text, one address per line
([`resources/address-list.example.txt`](resources/address-list.example.txt)):

```text
# comments and blank lines are ignored
# updated: 2026-11-06T00:00:00Z
0x000000000000000000000000000000000000dEaD  a note after the address is ignored
9xQeWvG816bUx9EPjHmaT23yvVM2ZWbrrpZb9PusVFin
scam-example.near
```

A `0x…` address matches in any case on every EVM network; any other form
exactly. The optional `# updated:` line dates the list (ISO 8601, UTC).

**The youngest list wins.** Every copy carries its date: OFAC's snapshot its
`published` (OFAC's Last-Modified), a text list its `# updated:` line (the
source's own, else its Last-Modified, else the time of the download). A sync
never replaces a younger copy with an older one, so a lagging mirror or cache
cannot roll a list back, and between the copy shipped with the library and
yours, the younger is used. `OfacSdn::published()` and
`AddressList::updatedAt()` give the date in use, to alert on a list that
stopped syncing. Every sync returns a `SyncResult`: `count`, `updated` (the
list's date) and `replaced` (false when the file held a younger list and was
kept).

Serve a list over HTTPS and any site can subscribe. A source of another
kind (a vendor feed, a database) is your own `ListSync`: implement
`sync(string $file): SyncResult`, write the format the list reads
(`OfacSdn::write()` for OFAC's, the text format above for the others), and
leave the old file in place when the source fails.

```php
// from your cron
(new AddressListSync($http, $requests, 'https://lists.example.org/denied.txt'))->sync('/var/lib/x402/denied.txt');

$dispatcher->addListener(AddressCheck::class, new AddressList(['/var/lib/x402/mine.txt', '/var/lib/x402/denied.txt']));
$dispatcher->addListener(AddressCheck::class, new ChainalysisOracle($http, $requests, $streams));
```

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
throws blocks too (`address_check_failed`). Other lists (your own, an API,
the token's blacklist with `TokenBlacklist`) subscribe as listeners; they run
only for an address OFAC's list passed.

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
  seller nothing. With a ledger, a replay of a delivered payment gets its stored
  response back (see Payment ledger), still without running anything.
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
  aborts anything dearer (`maxGasCost`, in wei: the caller sets it, `null` is
  no cap); one lock per network serialises the gas wallet's nonce; the transaction hash is known before it is sent, so a send that times
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
- Inside the middlewares, pending or silent settles are reconciled only when
  the buyer retries and a `lookup` answers; without a lookup they stay pending
  until their TTL. The ledger's open index lists them, but the library runs
  nothing on a timer: the sweep is the caller's (the demo app's
  `app:x402:reconcile`, from cron).
- `FileClaims` and `FileLedger` are single-host; several hosts need an atomic
  store.
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
