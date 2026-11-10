# Address screening

**OFAC's SDN list is on by default.** Every middleware and paying client asks
`OfacSdn` about the address first, with or without a dispatcher: a listed
payer is refused (`address_sanctioned`) before anything is claimed or run, and
a listed `payTo` is never signed for. The list comes from OFAC's own
[Sanctions List Service](https://ofac.treasury.gov/sanctions-list-service)
(`SDN.CSV`), every "Digital Currency Address" in it, every currency. A `0x…`
address is matched in any case on every EVM network; other forms exactly.

The library ships a copy of the list (`resources/ofac-sdn.json`), so the
check works offline from the first request. We refresh that copy now and then
(`bin/update-ofac.php`, before each release), but **keeping the list fresh is
your app's job**: OFAC updates it a few times a week. Run a `ListSync` from
your own cron, with your own PSR-18 client, and pass its file; the newer of
your copy and the shipped one is used.

```php
// daily, from your cron: downloads SDN.CSV, replaces the file only if the parse looks right
(new OfacSdnSync($psr18Client, $requestFactory))->sync('/var/lib/x402/ofac-sdn.json');

// every middleware and paying client takes it as `ofac:`
$ofac = new OfacSdn('/var/lib/x402/ofac-sdn.json');
new X402McpMiddleware($schemes, $price, $claim, $responses, $streams, $settle, ofac: $ofac);
new PayingMcpClient($client, $schemes, $budget, $streams, ofac: $ofac);
```

A snapshot that cannot be read refuses every address (fails closed). This
screens the addresses OFAC publishes, nothing more: names, countries or other
lists you are bound by go in your own listeners, below. Not legal advice.

**Turning it off** takes a name: pass `ofac: OfacSdn::off()`. Your seller then
takes money from, and your buyer pays, addresses OFAC names; whoever turns it
off answers for that. Leaving `ofac:` out never turns it off.

**More lists.** Each is an `AddressCheck` listener (below), asked only about
addresses OFAC's list passed:

| Listener | Source | Refuses with |
|---|---|---|
| `AddressList([$files], $reason)` | your own files, or a list another site publishes, synced with `AddressList::sync($http, $requests, $url, $file)` | `address_denied` (or `$reason`) |
| `TokenBlacklist` | the token contract's own blacklist (USDC, USDT), one `eth_call` | `address_blacklisted` |
| `ChainalysisOracle` | Chainalysis' on-chain sanctions oracle, one free `eth_call` on Ethereum, EVM addresses only | `address_sanctioned` |
| `ChainalysisApi($http, $requests, $key)` | Chainalysis' free sanctions API, any address form | `address_sanctioned` |

The oracle and the API are Chainalysis' copies of the sanctions lists, a second
opinion that may lag OFAC's either way. Each fails closed when its source does
not answer (`failOpen: true` passes instead, and reports `ScreeningSkipped`),
and a list file never synced refuses everyone until it is. A 4xx from the API
(a bad key) is never failed open.

The list format is plain text, one address per line
([`resources/address-list.example.txt`](../resources/address-list.example.txt)):

```text
# comments and blank lines are ignored
# updated: 2026-11-06T00:00:00Z
0x000000000000000000000000000000000000dEaD  a note after the address is ignored
9xQeWvG816bUx9EPjHmaT23yvVM2ZWbrrpZb9PusVFin
scam-example.near
```

A `0x…` address matches in any case on every EVM network; any other form
exactly. The optional `# updated:` line dates the list (ISO 8601, UTC).

**The youngest list wins.** Every copy carries its date: OFAC's snapshot its
`published` (OFAC's Last-Modified), a text list its `# updated:` line (the
source's own, else its Last-Modified, else the time of the download). A sync
never replaces a younger copy with an older one, so a lagging mirror or cache
cannot roll a list back, and between the copy shipped with the library and
yours, the younger is used. `OfacSdn::published()` and
`AddressList::updatedAt()` give the date in use, to alert on a list that
stopped syncing. Every sync returns a `SyncResult`: `count`, `updated` (the
list's date) and `replaced` (false when the file held a younger list and was
kept).

Serve a list over HTTPS and any site can subscribe. A source of another
kind (a vendor feed, a database) is your own `ListSync`: implement
`sync(string $file): SyncResult`, write the format the list reads
(`OfacSdn::write()` for OFAC's, the text format above for the others), and
leave the old file in place when the source fails.

```php
// from your cron
(new AddressListSync($http, $requests, 'https://lists.example.org/denied.txt'))->sync('/var/lib/x402/denied.txt');

$dispatcher->addListener(AddressCheck::class, new AddressList(['/var/lib/x402/mine.txt', '/var/lib/x402/denied.txt']));
$dispatcher->addListener(AddressCheck::class, new ChainalysisOracle($http, $requests, $streams));
```

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
throws blocks too (`address_check_failed`). Other lists (your own, an API,
the token's blacklist with `TokenBlacklist`) subscribe as listeners; they run
only for an address OFAC's list passed.

```php
$dispatcher->addListener(AddressCheck::class, function (AddressCheck $check) use ($denied) {
    if (in_array(strtolower($check->address), $denied, true)) {
        $check->reject('address_denied');
    }
});
```
