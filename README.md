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
