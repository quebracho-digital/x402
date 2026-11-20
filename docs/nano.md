# Nano: the `exact` scheme

`NanoExact` verifies x402 `exact` payments in XNO on Nano (`nano:mainnet`), and pays them through a wallet you
pass in. `NanoFacilitator` checks the payment on a Nano node that you choose. Experimental, and off until a site
registers the network.

## What the buyer sends

Nano has no token contract and no authorization to sign. The payment is a send block, which is final once the
network confirms it, and there is no fee. The buyer's wallet sends exactly `amount` raw to `payTo`, and the
payment names that block:

```json
{"block": "<64 upper-case hex>", "account": "nano_…", "orderId": "<64 lower-case hex>"}
```

A block has no memo field. The send is tied to this payment through its `representative`, as in option C of the
[xno-call-binding standard](https://github.com/dhyabi2/xno-call-binding/blob/main/docs/BINDING-STANDARD.md):

```
representative = account(sha256("xno-binding/v1/rep|" ‖ orderId ‖ "|" ‖ payTo ‖ "|" ‖ amount))
```

`orderId` is 32 random bytes drawn by the buyer, and only the seller sees it. Block hashes are public on the
ledger, so anyone can read one. Without the order id behind its representative, though, nobody else can present
that block as their own payment. `NanoExact::representative()` computes the account.

`pay()` needs `$send`, the buyer's wallet: `fn (string $payTo, string $raw, string $representative): array{hash,
account}`. It sends the block, waits for the confirmation and returns the block's hash. The library never holds
a Nano key.

## Verify

`verify()` works offline. It checks one spelling per value (an upper-case block hash, a `nano_` account with a
valid checksum, a lower-case order id, no other keys), the version, the scheme and the network. It also checks
that the payment echoes the offer: asset `XNO`, `payTo`, and `amount` in raw.

`NanoFacilitator::verify()` and `settle()` make one `block_info` call each. They refuse the payment unless the
block is:

- confirmed;
- a state block of subtype `send`;
- sent by the payment's `account` to `payTo`;
- for exactly `amount` raw;
- carrying the order's representative;
- received by the node no more than 540 s ago (`NanoExact::MAX_TIMEOUT`).

`settle()` broadcasts nothing, since the block has already moved, and returns the block hash as the transaction.
If the node cannot be reached, it throws `FacilitatorException`. If the node answers with an error such as `Block
not found`, the payment is refused. Each refusal has its own reason code (`NanoFacilitator::REASON_*`).

## Replay

`Settlement::key` claims a Nano payment by network and block hash, so one block buys once, whatever order id
comes with it. Use a claim that survives a restart (`FileClaims`, `RedisLedger`). The claim lives 600 s, and a
block older than 540 s by the node's clock is refused, so a block cannot be sold again once its claim is gone.
The extra 60 s cover a node clock that runs ahead (`NanoExact::SKEW`).

## Amounts and offers

XNO has 30 decimals: 1 XNO is 10^30 raw. It is not worth a dollar, so a site prices in XNO, and the offer is
`upfront`: the block is checked before the handler runs.

```php
Networks::addNano();
$req = Networks::requirementsInToken(NanoExact::NETWORK, '0.001', $payTo); // 10^27 raw
$facilitator = new NanoFacilitator($http, $f, $f, 'https://your-node.example/rpc');
```

`$payTo` must be a `nano_` or `xrb_` address with a valid checksum, and the offer carries it as `nano_`. A
mistyped address fails when the offer is built.
