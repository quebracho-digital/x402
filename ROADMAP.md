# quebracho/x402: roadmap

Developed in github.com/quebracho-digital/x402, which feeds
[Packagist](https://packagist.org/packages/quebracho/x402). Release = merge to `main`, tag.

## Done

- Budget release by the chain, deferred screening, and strict NEAR screening (D8).
- Solana devnet (`SolanaExact`, offline verify; G6).
- An index of open payments in `PaymentLedger` (`open`, `openKeys`, `close`) for a reconciler (D20).
- Events for what the library carries on without (`PaymentFault`, `AddressRejected`, `ScreeningSkipped`) and for the buyer (`PurchaseIntent`, `PurchaseOutcome`, `BudgetReclaim`); `PaymentLog` and `PaymentRecord` as listeners.
- From the demo app: `Reconciler`, `ReceiptLookup`, `AuthorizationState`, `RedisLedger`, `PayeeAllowlist`, and job mode's framework-free core (`Quebracho\X402\Job`).
- Extension points: optional `Scheme` interfaces (`SpendsOnce`, `Expires`, `AddressForm`, `VetsOffers`), a `Facilitator` given whole to the middlewares, `Seller` and `Buyer`, PSR-20 clocks, `X402Exception`.
- From the demo app: job mode's wiring (`JobDesk`, `JobDeposit`, `JobDelivery`, `JobIdOnRefusal`, `JobSlots`), `PriceList`, `SensitiveRules`, `Screening`, `Facilitators`.
- The build enforces the rules of `CONTRIBUTING.md` (`ArchitectureTest`, `DocblockTest`); the shipped OFAC list is refreshed by a daily workflow.

## Open

- [ ] Send the client one generic rejection code and keep the detail (which list) in the log.
- [ ] Separate allowlists for the payer and payTo roles.
- [ ] Verify the EIP-712 domains of USDC on Ethereum Sepolia, Avalanche Fuji and Polygon Amoy.
- [ ] NEAR out of experimental: RPC checks (`view_access_key`, nonce, balances).
- [ ] `Scheme` stable at 1.0.
- [ ] `Networks` as an injectable registry (today a static one), so a chain family of your own can register its networks; for 1.0.
- [ ] PHPStan level 8 (29 findings today, the library is at level 6), then typed shapes for requirement, payment and receipt.
- [ ] `HttpFacilitator::checkSettle` and `Budget::reclaim` still read EIP-3009 fields themselves; route them through the scheme once it can be tested against a live facilitator.
- [ ] Casper offers in the paying clients: `CasperExact` implements `VetsOffers` (#5).

## Notes

**Screening latency.** A PHP Fiber cannot make an HTTP call progress on its own: it only pauses and resumes code, with no event loop underneath. Symfony HttpClient can do the same job without one:
- `request()` returns at once, and the network moves forward whenever the code waits on a response.
- The overlap only hides latency when the tool takes as long as the API. With a fast tool, the API sets the pace on a payer's first payment; after that, the cache answers.

**NEAR.** Offline we cannot prove that the key belongs to `sender_id`, so anyone can claim a clean one. Screening on NEAR therefore needs the RPC check.
