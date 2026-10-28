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
use Quebracho\X402\{EvmExact, Networks, X402Middleware};

// Price per request: a list of offers, or null when it is free.
$price = fn ($request) => [Networks::requirements('eip155:84532', '0.01', $payTo)];
$middleware = new X402Middleware([new EvmExact()], $price, $psr17, $psr17, $settle);
```

Unpaid → `402` with `PAYMENT-REQUIRED`. Paid → verify offline, run the
handler, settle only on 2xx, answer with `PAYMENT-RESPONSE`. Without `$settle`
(a facilitator callback) it runs in mock mode: real verification, no settle.

## Buyer

```php
use Quebracho\X402\{Budget, EvmExact, PayingHttpClient, Signer};

$client = new PayingHttpClient(
    $psr18,
    [new EvmExact(new Signer($testnetKey))],
    Budget::usd('eip155:84532', '0.05'),
);
```

Pays a 402 once and retries the same request. The budget is a per-network cap,
in memory, spent only when the seller confirms settlement.

## Networks

Built in: Base Sepolia, Arc testnet, Ethereum Sepolia, Avalanche Fuji, Polygon
Amoy. Any EVM token with EIP-3009:

```php
Networks::add(chainId: 4202, asset: '0x…', name: 'USDC', version: '2');
```

## Schemes

`Scheme` (`supports`, `pay`, `verify`) is one chain family; experimental until
1.0. `EvmExact` is the stable one. `NearExact` (`near:testnet`) is
experimental, see [docs/near.md](docs/near.md).

## Tests

```bash
vendor/bin/phpunit tests   # no network
```

See [NOTICE](NOTICE) for the origin of some classes.
