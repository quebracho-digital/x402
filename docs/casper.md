# Casper: the `exact` scheme

`CasperExact` pays and verifies x402 `exact` payments on Casper
(`casper:casper`, `casper:casper-test`). It follows
[`@make-software/casper-x402`](https://github.com/make-software/casper-x402),
which is the code the CSPR.cloud facilitator runs. Experimental.

## What the buyer signs

A CEP-18 `transfer_with_authorization`, as Casper EIP-712 typed data
([casper-ecosystem/casper-eip-712](https://github.com/casper-ecosystem/casper-eip-712)):

```
EIP712Domain(string name,string version,string chain_name,bytes32 contract_package_hash)
TransferWithAuthorization(address from,address to,uint256 value,uint256 validAfter,uint256 validBefore,bytes32 nonce)
```

- `chain_name` is the CAIP-2 id as written in the offer (`casper:casper-test`),
  `contract_package_hash` the token's package hash (`asset`), `name` and
  `version` come from the offer's `extra`.
- An `address` is 33 bytes: `00` and the account hash
  (blake2b-256 of `ed25519` or `secp256k1`, a zero byte, the raw key). Casper
  EIP-712 encodes it as its keccak-256, not left-padded as on EVM.
- `validAfter` is now minus 600 s, `validBefore` now plus `maxTimeoutSeconds`,
  the nonce 32 random bytes.

The payload, all hex without `0x`:

```json
{
  "signature": "01…",
  "publicKey": "01…",
  "authorization": {"from": "00…", "to": "00…", "value": "1500000000", "validAfter": "…", "validBefore": "…", "nonce": "…"}
}
```

The signature is the Casper algorithm byte (`01` ed25519) and 64 bytes.

## Verify, offline

`verify()` checks what the payment alone proves: one spelling per value,
version, scheme, network, the echoed offer, recipient and value exactly as the
facilitator compares them, the validity window (6 s left to settle, no longer
than the offer plus 60 s), that `publicKey` hashes to `from`, and the
signature over the digest. Balance and spent nonces are the facilitator's.

`Settlement::key` claims a Casper payment by network, payer and nonce, as on EVM.

## Amounts

wCSPR has 9 decimals: 1 CSPR is 1 000 000 000 motes. `Networks::requirements`
refuses a price with more decimals than the token has; nothing is rounded.

## Registering a token

No Casper token is built in: register the package hash and EIP-712 domain you
read from the token contract.

```php
Networks::addCasper(CasperExact::TESTNET_NETWORK, $packageHash, $name, $version);
$req = Networks::requirements(CasperExact::TESTNET_NETWORK, '0.5', $payTo); // 500000000 motes
```

## Facilitator

`HttpFacilitator::CSPR_CLOUD` (`https://x402-facilitator.cspr.cloud`).
`GET /supported` is public and lists both networks with their `feePayer`;
`/verify` and `/settle` need a CSPR.cloud access key, passed as `$bearer`.

## Not yet

- secp256k1 (`02…`) keys: refused as `invalid_payload`. The reference signs
  sha256 of the digest with ECDSA; adding it is a verify branch.
- The paying client's `Budget` does not know Casper offers yet.

## Test vectors

`tests/CasperExactTest.php` pins a payment that the reference client signed
(RFC 8032 test key, fixed clock and nonce) and that the reference
facilitator's `verify()` accepted: digest and signature match byte for byte.
