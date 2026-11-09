# Protections, layer by layer

Every line is backed by a test in `tests/` (`vendor/bin/phpunit tests`). The
reasons are in the repository's [DECISIONS.md](https://github.com/quebracho-digital/quebracho-x402-mcp/blob/main/DECISIONS.md); the
attack-by-attack list is in the [demo repository README](https://github.com/quebracho-digital/quebracho-x402-mcp#attacks-it-resists).

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

## Stop switch

A file that stops all payments while it exists. Give the same one to the seller's middleware and to the buyer's
client (`stop:` / the last constructor argument) and one command stops both:

```php
$stop = new Quebracho\X402\Stop('/var/run/myapp/x402.stop');
$stop->engage('suspected key leak');   // or just: touch /var/run/myapp/x402.stop
$stop->release();
```

- Seller: a paid request is answered `payments_paused` (HTTP 503, or JSON-RPC -32000 for MCP) before anything
  new is verified, claimed or run, and again right before each settle: a request that was already running when
  the switch went on is not charged and its answer is withheld. Free requests and free tools keep working.
- A payment the ledger already holds is still delivered while paused (a replay, or an upfront tool that still
  owes its answer): the buyer paid for it and its signature may expire before the switch is released.
- Buyer: no offer is reserved or signed. The caller sees the 402 itself, and `PurchaseOutcome` reports
  `refused` with the reason `payments_paused`. It is checked again after the payee screening, which can take time.
- It is checked on every request, so it works in long-running workers. Nothing sets it by itself.
- It does not recall a payment already signed or a settle already sent.
- Use an absolute path in a directory only the application's user can write to, never `/tmp`: `engage()` refuses
  a link, a file somebody else owns and a directory others can write to. Anything at the path (even a directory)
  counts as engaged, and so does a flag directory the process cannot search, so a mistake pauses payments
  rather than silently leaving them on.
