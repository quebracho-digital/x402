# quebracho/x402

[x402](https://www.x402.org/) payments for PHP: sign and verify payments, a
PSR-15 middleware for sellers and a PSR-18 client for buyers. No framework,
no LLM code. MIT.

> **Testnet only.** No mainnet networks are built in.

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

Unpaid → `402` with `PAYMENT-REQUIRED`. Paid → verify offline, run the
handler, settle only on 2xx, answer with `PAYMENT-RESPONSE`. Without `$settle`
(a facilitator callback) it runs in mock mode: real verification, no settle.

Optional, by name, on both middlewares:

- `verify:` `fn($payment, $req): ?string`, an online check (`$facilitator->verify`)
  before the claim: an empty wallet runs nothing and takes no claim.
- `release:` `$claims->release(...)`: a payment that charged nothing (handler
  failed, settle failed with no transaction) is good again. A pending settle
  keeps its claim. `FileClaims::prune($seconds)` clears old claims.
- `resource:` the 402's `resource` (canonical URL, description, mimeType).
- `extra.paymentFlow = 'upfront'` in an offer settles before the handler runs;
  once money moved, the receipt goes with whatever the handler answers.
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

`PayingHttpClient` pays a 402 once and retries the same request. The budget is a per-network cap,
in memory, spent when it signs (a signed authorization is money the seller
can settle) and never refunded on the seller's word. Both buyers sign only
known tokens (`Networks`), integer amounts and validities up to 10 minutes.
With `Budget::usd($network, $usd, $used)`, where `$used` reads the token's
`authorizationState` on chain, a signature the seller never settled frees
its amount once it expires unused.

## Networks

Built in: Base Sepolia, Arc testnet, Ethereum Sepolia, Avalanche Fuji, Polygon
Amoy. Any EVM token with EIP-3009:

```php
Networks::add(chainId: 4202, asset: '0x…', name: 'USDC', version: '2');
```

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
1.0. `EvmExact` is the stable one. `NearExact` (`near:testnet`) is
experimental, see [docs/near.md](docs/near.md).

## Hardening

What it resists, each backed by a test, is listed in the
[repository README](../../README.md#attacks-it-resists).

## Tests

```bash
vendor/bin/phpunit tests   # no network
```

See [NOTICE](NOTICE) for the origin of some classes.
