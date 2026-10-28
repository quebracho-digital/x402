# NEAR Protocol Payment Scheme Research & Specification
> **Superseded on payload shape and timeout:** `NearExact` now follows the official spec, [x402-foundation/x402 `specs/schemes/exact/scheme_exact_near.md`](https://github.com/x402-foundation/x402/blob/main/specs/schemes/exact/scheme_exact_near.md): payload is only `{"signedDelegateAction": base64}`, `max_block_height = height + maxTimeoutSeconds`. Where this research note disagrees, the spec wins.

## Overview
This document specifies how NEAR Protocol (`near:testnet`) can be integrated as a second chain family in `packages/x402` (PHP library for x402 payment HTTP headers and verification).

---

## 1. Third-Party Authorized USDC (NEP-141) Payment on NEAR Testnet

### NEP-366 Delegate Actions / Meta-Transactions
NEAR Protocol natively supports meta-transactions via **NEP-366 Delegate Actions** ([NEP-366 Specification](https://raw.githubusercontent.com/near/NEPs/master/neps/nep-0366.md)).
- **Buyer (Sender)**: Signs a `DelegateAction` specifying actions (e.g. `ft_transfer`), receiver contract (`usdc.fakes.testnet`), sender account ID, receiver account ID, key nonce, max block height, and signer public key.
- **Relayer / Facilitator (Submitter)**: Wraps the `SignedDelegateAction` inside an `Action::Delegate` transaction targeting `delegate_action.sender_id` and submits it to the chain, paying all NEAR network gas and storage fees.

### NEP-141 `ft_transfer`
The `DelegateAction` contains a `FunctionCall` action targeted at the USDC token contract:
- **Method**: `ft_transfer`
- **Arguments (JSON)**:
  ```json
  {
    "receiver_id": "<payTo account id>",
    "amount": "<amount in atomic units as string>",
    "memo": "x402 payment"
  }
  ```
- **Attached Deposit**: `1` yoctoNEAR (`1` as `u128` in Borsh serialization) as required by NEP-141 for security against unattached function calls.
- **Gas**: Typically `30,000,000,000,000` gas units (`30 TGas`).

### Storage Deposit Requirements (NEP-145)
NEP-141 tokens require accounts to be registered on the token contract via NEP-145 `storage_deposit`.
- **Pre-requisite**: The recipient (`payTo`) and sender (`sender_id`) must have a storage deposit on `usdc.fakes.testnet`.
- **Relayer Responsibility**: If the recipient is not registered, the relayer or facilitator can prepend a `storage_deposit` call before submitting the delegated action transaction, or the receiver must be registered beforehand. Offline verification in x402 assumes account registration is validated on-chain during settlement by the facilitator.

---

## 2. Borsh Serialization & Ed25519 Signing Payload

### Exact Borsh Layouts

#### 1. `DelegateAction`
Borsh fields in order:
1. `sender_id`: `String` (u32 length prefix + UTF-8 bytes)
2. `receiver_id`: `String` (u32 length prefix + UTF-8 bytes)
3. `actions`: `Vec<NonDelegateAction>` (u32 length prefix + elements)
   - For `FunctionCall`:
     - Action discriminant tag: `u8` = `2` (`FunctionCall`)
     - `method_name`: `String` (u32 length + UTF-8 bytes)
     - `args`: `Vec<u8>` (u32 length + raw JSON bytes)
     - `gas`: `u64` (little-endian)
     - `deposit`: `u128` (little-endian; `1` yoctoNEAR = `0x01` followed by 15 zero bytes)
4. `nonce`: `u64` (little-endian)
5. `max_block_height`: `u64` (little-endian)
6. `public_key`: `PublicKey`
   - Key tag: `u8` = `0` (`ED25519`)
   - Key data: `[u8; 32]` raw Ed25519 public key bytes

#### 2. `SignableMessage` (NEP-461 Domain Separation Prefix)
NEAR uses NEP-461 domain separation prefix for signed off-chain payloads ([nearcore signable_message.rs](https://github.com/near/nearcore/blob/master/core/primitives/src/signable_message.rs)):
- **Discriminant (`MessageDiscriminant`)**: `u32` (little-endian)
  - Formula for on-chain NEP messages: `(1 << 30) + nep_number`
  - For NEP-366 meta-transactions: `(1 << 30) + 366` = `1073742190` (`0x4000016E` in hex, or `6E 01 00 40` in 4-byte LE Borsh).
- **Message**: Borsh-serialized `DelegateAction`.

`SignableMessage` Borsh layout:
```
[4 bytes: discriminant u32 LE (1073742190)] + [Borsh bytes of DelegateAction]
```

#### 3. Ed25519 Signing Payload
1. `SignableMessage` is serialized to Borsh bytes `B`.
2. SHA-256 hash `H = sha256(B)` is calculated (32 bytes).
3. `H` is signed using Ed25519 (`sodium_crypto_sign_detached` in PHP `ext-sodium`) using the buyer's private key.

#### 4. `SignedDelegateAction`
1. `delegate_action`: `DelegateAction` struct
2. `signature`: `Signature`
   - Key tag: `u8` = `0` (`ED25519`)
   - Signature data: `[u8; 64]` raw Ed25519 signature bytes

---

## 3. Nonce, Max Block Height & Testnet USDC Contract ID

### Nonce & Max Block Height
- **`nonce`**: Must be strictly greater than the current `nonce` of the sender's access key on NEAR (`access_key.nonce + 1`). Obtained from NEAR RPC (`query` method with `request_type: view_access_key`). Passed explicitly to `pay()`.
- **`max_block_height`**: The block height limit after which the transaction is invalid on-chain. Obtained from NEAR RPC (`status` or `block`) and adding a validity window (e.g., current block height + 100 blocks ~ 2 minutes). Passed explicitly to `pay()`.

### Testnet USDC Contract ID
- **Contract ID**: `usdc.fakes.testnet`
- **Decimals**: `6`
- **Source**: Verified via NEAR testnet RPC `call_function` `ft_metadata` on `usdc.fakes.testnet` ([NEAR Testnet RPC](https://rpc.testnet.near.org)).

---

## 4. Existing x402 Specs, Scheme Proposals, or Facilitators for NEAR

- **x402 Standard / Specs**: The main x402 specification focuses on HTTP status code `402 Payment Required` and CAIP-2 network identifiers.
- **NEAR Facilitators / Scheme Proposals**: UNVERIFIED — As of October 2026, no official or standardized x402 facilitator or scheme proposal specifically published for NEAR Protocol was found in public x402 repositories. The implementation in `packages/x402` provides a standard compliant `Scheme` implementation using native NEAR meta-transactions (`near:testnet` CAIP-2 identifier).

---

## 5. Citations & Sources

1. **NEP-366: Meta Transactions**:
   URL: https://raw.githubusercontent.com/near/NEPs/master/neps/nep-0366.md
2. **NEAR Nomicon Actions & DelegateAction Spec**:
   URL: https://nomicon.io/RuntimeSpec/Actions#DelegateAction
3. **nearcore Implementation of DelegateAction & SignableMessage (NEP-461)**:
   URL: https://raw.githubusercontent.com/near/nearcore/master/core/primitives/src/action/delegate.rs
   URL: https://raw.githubusercontent.com/near/nearcore/master/core/primitives/src/signable_message.rs
4. **NEP-141: Fungible Token Standard**:
   URL: https://github.com/near/NEPs/blob/master/neps/nep-0141.md
5. **NEAR Testnet RPC (`usdc.fakes.testnet` Metadata)**:
   URL: https://rpc.testnet.near.org
