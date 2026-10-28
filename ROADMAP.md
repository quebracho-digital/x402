# quebracho/x402: roadmap

Lives in the hackathon repo for now. It moves to its own repo after the
hackathon (`git subtree split`, history kept), then goes on Packagist.

## Done

- Budget release by the chain, deferred screening, and strict NEAR screening (D8).

## Open

- [ ] Send the client one generic rejection code and keep the detail (which list) in the log.
- [ ] Separate allowlists for the payer and payTo roles.
- [ ] Verify the EIP-712 domains of USDC on Ethereum Sepolia, Avalanche Fuji and Polygon Amoy.
- [ ] Solana devnet (`SolanaExact`).
- [ ] NEAR out of experimental: RPC checks (`view_access_key`, nonce, balances).
- [ ] `Scheme` stable at 1.0.

## Notes

**Screening latency.** A PHP Fiber cannot make an HTTP call progress on its own: it only pauses and resumes code, with no event loop underneath. Symfony HttpClient can do the same job without one:
- `request()` returns at once, and the network moves forward whenever the code waits on a response.
- The overlap only hides latency when the tool takes as long as the API. With a fast tool, the API sets the pace on a payer's first payment; after that, the cache answers.

**NEAR.** Offline we cannot prove that the key belongs to `sender_id`, so anyone can claim a clean one. Screening on NEAR therefore needs the RPC check.
