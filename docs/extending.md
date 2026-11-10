# Extending

How the library is put together, for whoever adds to it: another facilitator, another chain family, another
store. Everything here is optional; the built-in pieces work without it.

## The pieces

| You want | Implement | Shipped |
| --- | --- | --- |
| to settle somewhere else | `Facilitator` (`verify`, `settle`) | `HttpFacilitator`, `CircleFacilitator`, `ChainFacilitator`, `MockFacilitator` |
| to ask what became of a settle | `PaymentStatus` | `CircleFacilitator` |
| another chain family | `Scheme`, plus the optional interfaces below | `EvmExact`, `SolanaExact`, `NearExact`, `CasperExact` |
| payments remembered elsewhere | `PaymentLedger` | `FileLedger`, `RedisLedger` |
| jobs stored elsewhere | `Job\JobStore` | `Job\FileJobStore` |
| another limit on running jobs | `Job\JobSlots` | `Job\FileJobSlots` |
| another screening list | a listener of `AddressCheck` | `OfacSdn`, `AddressList`, `Screening`, `ChainalysisApi`, `TokenBlacklist`, `PayeeAllowlist` |
| a list kept fresh from another source | `ListSync` | `OfacSdnSync`, `AddressListSync` |

## Build by name

`Seller` and `Buyer` take the same options as the constructors of the middlewares and the paying clients,
one method each:

```php
$seller = Seller::with([new EvmExact()], $facilitator, $psr17, $psr17)
    ->ledger(new FileLedger($dir))
    ->events($dispatcher)
    ->sensitive(new SensitiveRules($rules));

$http = $seller->http(fn (ServerRequestInterface $r): ?array => $accepts);
$mcp = $seller->mcp(new PriceList('eip155:8453', $payTo, '0.01', paid: ['wisdom']));

$buyer = Buyer::with([new EvmExact($signer)], Budget::usd('eip155:8453', '0.05'))->events($dispatcher);
$client = $buyer->mcp($psr18, $psr17);
```

A seller needs a `ledger()` or `claims()`; building a middleware without either throws.

## A facilitator

Pass it whole: it settles, and it verifies online before anything runs.

```php
$facilitator = Facilitators::from($_ENV['X402_FACILITATOR'], $psr18, $psr17, $psr17, $apiKey);
$seller = Seller::with($schemes, $facilitator, $psr17, $psr17);
```

A closure is still accepted wherever a facilitator is, for a seller that wraps the settle (job mode's
`JobDesk::settle` does). Why a closure at all: the middlewares ask one question, "settle this payment", and
some answers are not a facilitator (a wrapper that writes a row first, a mock, a test).

## A chain family

`Scheme` is what the x402 spec asks of every chain: pay, verify, and say who pays. The core needs four more
answers, and asks the scheme for each through an optional interface. It never looks at a network's prefix.

| Interface | The core asks | Without it |
| --- | --- | --- |
| `SpendsOnce` | what the chain spends once in this payment, for the key in the claim and the ledger | the whole payload is the key |
| `Expires` | when the payment expires, and whether it can wait for slow work | claims live `Settlement::TTL`; a tool with a `$duration` refuses it (`unsupported_scheme`) |
| `AddressForm` | whether a `payTo` is an address, its one spelling, and whether two are the same | no check on `payTo`; compared byte for byte |
| `VetsOffers` | whether a buyer may sign this offer | `Budget` refuses every offer: the scheme cannot buy |

A scheme of your own that implements `SpendsOnce` must be among the schemes given to
`Settlement::key($payment, $schemes)` wherever you compute a key outside the middlewares (they pass theirs).

What is still EVM only, and says so: the on-chain tools (`ChainFacilitator`, `Reconciler`, `ReceiptLookup`,
`AuthorizationState`, `TokenBlacklist`), the budget's `reclaim()` (EIP-3009 nonces), and the networks
`Networks::add*()` can register. A new family also needs its networks in `Networks`.

## Time

Whatever takes a `$clock` takes a PSR-20 `ClockInterface` (or a closure that returns Unix seconds): pass
your framework's clock, and a frozen one in tests.

## Errors

Every exception class of the library implements `X402Exception`. A mistake in how the library is called or
configured throws PHP's own `\InvalidArgumentException` or `\LogicException` instead: those are for you to
fix at deploy, and `PriceList`, `SensitiveRules`, `Networks::assertSupported()` and `LedgerCheck::run()`
exist to raise them there.
