# Concordium: `exact` with sponsored CCD transfers

The `exact` scheme on Concordium is a **V1 sponsored CCD transfer**: the buyer
signs a transfer and leaves the sponsor slot empty; the facilitator fills that
slot, signs, submits and pays the energy. The resource is served only after the
transfer is finalized by ConcordiumBFT.

Spec: [x402 `scheme_exact_concordium.md`](https://github.com/x402-foundation/x402/blob/main/specs/schemes/exact/scheme_exact_concordium.md).

## The offer

A Concordium offer is priced in CCD, not in dollars (this library does not guess
an exchange rate), and names the facilitator that will sponsor it:

```php
use Quebracho\X402\{ConcordiumExact, Networks};

$facilitatorAccount = '<the facilitator account, base58check>';
$offer = Networks::requirementsInToken(
    ConcordiumExact::TESTNET_NETWORK,   // ccd:4221332d34e1694168c2a0c0b3fd0f27
    '0.01',                             // 0.01 CCD = 10000 microCCD
    $sellerAccount,
    60,
    [new ConcordiumExact(feePayer: $facilitatorAccount)],
);
// amount: "10000", asset: "CCD", extra.feePayer: the sponsor above
```

The offer is `upfront` (`extra.paymentFlow: "upfront"`): the middleware settles
the transfer before it runs the handler, so a handler with side effects never
runs for a payment that did not finalize. This matches Casper's `exact` offer.

Account addresses have one base58check spelling. `Concordium::decodeAddress()`
re-encodes the bytes and refuses a non-canonical input, so a leading `1` cannot
produce a second claim key or a second screening string.

`Networks::requirements()` (a price in dollars) refuses a Concordium network on
purpose: it would charge a dollar amount as if one CCD were one dollar. Use
`requirementsInToken()`.

Networks built in:

| Network | CAIP-2 |
|---------|--------|
| Testnet | `ccd:4221332d34e1694168c2a0c0b3fd0f27` |
| Mainnet | `ccd:9dd9ca4d19e9393877d2c44b70f89acb` |

## Buying

The buyer needs its account address and its Ed25519 seed (32 bytes, hex), and a
way to read the next sequence number from a node. `GrpcConcordiumNode` provides
that over gRPC-web:

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Quebracho\X402\{ConcordiumExact, GrpcConcordiumNode, GrpcWeb};

$psr17 = new Psr17Factory();
$node = new GrpcConcordiumNode(new GrpcWeb($psr18, $psr17, $psr17, 'https://grpc.testnet.concordium.com:20000'));

$buyer = new ConcordiumExact(
    buyer: ['address' => $buyerAccount, 'signKey' => $buyerSeedHex],
    nonceOf: fn (string $address): int => $node->nextNonce($address),
);
$payment = $buyer->pay($required, $offer);
```

`PayingHttpClient`/`PayingMcpClient` can use it through `Budget` and the usual
`Scheme` path. A `Budget` cap for `ccd:*` is in microCCD:

```php
$budget = new Budget(['ccd:4221332d34e1694168c2a0c0b3fd0f27' => '100000']); // 0.1 CCD
```

The scheme signs a single sender signature (credential 0, key 0). Multi-key
accounts and PLT tokens are not supported yet.

## Selling: the facilitator

`ConcordiumFacilitator` is the seller's facilitator. It verifies offline first
(`ConcordiumExact`), then asks the node for what only the chain knows:

- the sender's account exists, and its **public keys** are the ones the on-chain
  account has (the sender's signature is checked cryptographically against
  them, including the credential and account thresholds);
- the sequence number is the account's next one;
- the available balance covers the transfer;
- the sponsor named in the header is the facilitator's own account.

On `settle()` it adds the sponsor signature, submits the transaction and polls
`GetBlockItemStatus` until it is finalized. A settle counts only when the
finalized transaction is an `account_transfer` with the offer's exact amount and
`payTo`; a `none` effect (a rejected transaction) is a failure with the hash so
the payment can be reconciled.

With a ledger, the stored outcome is bound to the offer it paid for: a nonce
from a cheap offer cannot authorize a different one. See
[docs/seller.md](seller.md), "Payment ledger".

```php
use Quebracho\X402\{ConcordiumExact, ConcordiumFacilitator, GrpcConcordiumNode, GrpcWeb};

$facilitator = new ConcordiumFacilitator($node, $sponsorAccount, $sponsorSeedHex, ConcordiumExact::TESTNET_NETWORK);
// $middleware = Seller::with([new ConcordiumExact(feePayer: $sponsorAccount)], $facilitator, ...);
```

The fourth argument is the CAIP-2 network of the node. `verify()` and
`settle()` refuse any other network before touching the node, so a mainnet
offer cannot be settled on a testnet node (a Concordium transfer does not carry
the network in its signed bytes).

The sponsor is a plain account: the facilitator signs with credential 0, key 0.
Keep the seed in a secret store, never in the repository.

The PSR-18 client that carries `GrpcWeb` must not follow redirects: the request
contains a signed transaction and, on settle, the sponsor's signature. As with
`HttpFacilitator`, the signed payment goes to the node's host only.

## What was verified

- Offline digest: the same inputs as the Kalibang spike's formula produce the
  digest whose transaction finalized on testnet on 2026-10-21.
- gRPC-web and decoding against the public testnet node: `GetAccountInfo`
  (balance, threshold, credential 0's key), `GetNextAccountSequenceNumber`, and
  `GetBlockItemStatus` of the 2026-10-21 spike transaction.
- A real sponsored transfer through `ConcordiumFacilitator` and
  `GrpcConcordiumNode` on testnet on 2026-11-23:
  [`01f69355…d0e9b`](https://ccdexplorer.io/testnet/transaction/01f69355476078ccca7923b0610e24e30e90713cd3cc552c3e9ecec1938d0e9b),
  finalized, 1 microCCD, sponsor paid the energy.
- Tests that fail when the sender's signature is not the account's, when the
  nonce is stale, when the balance is short, or when the finalized transfer is
  not the offer's.

## Limits

- Native CCD only (no PLT); no memo and no bundled operations.
- One sender signature: a multi-key credential or a multi-credential account is
  outside the scheme as implemented (`numSignatures` must be 1).
- The facilitator's sponsor is one account at credential 0, key 0.
- A finalized transaction whose `account_transfer` cannot be read is reported
  as `settlement_mismatch`; the transaction hash is always returned for
  reconciliation.
