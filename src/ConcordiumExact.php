<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Clock\ClockInterface;

/**
 * The `exact` scheme on Concordium (x402-foundation specs/schemes/exact/
 * scheme_exact_concordium.md): a V1 sponsored CCD transfer, signed by the
 * buyer, its sponsor slot empty for the facilitator.
 *
 * Offline rules only (the spec's checks 1 to 7 and 9). The sender's signature
 * against its on-chain keys, the nonce and the balance are the facilitator's.
 *
 * ponytail: native CCD only. A PLT (tokenUpdate, CBOR operations) is refused as
 * unsupported_asset; add it when a PLT is the price.
 */
final class ConcordiumExact implements Scheme, SpendsOnce, Expires, AddressForm, VetsOffers, ShapesOffers
{
    // Genesis hashes, as CAIP-2 names a Concordium network.
    public const string TESTNET_NETWORK = 'ccd:4221332d34e1694168c2a0c0b3fd0f27';
    public const string MAINNET_NETWORK = 'ccd:9dd9ca4d19e9393877d2c44b70f89acb';
    // CCD has six decimals: 1 CCD = 1_000_000 microCCD.
    public const int DECIMALS = 6;
    // Seconds a payment must still be valid for when it is settled, so it does not expire on its way into a block.
    public const int SLACK = 3;
    // The longest offer timeout: a replay is refused by the claim, which lives Sale::TTL (600 s), and a
    // payment may outlive its offer by a few seconds of clock skew.
    public const int MAX_TIMEOUT = 540;

    /**
     * @param ?array                       $buyer    The paying account and its Ed25519 seed (hex); a seller that
     *                                               only verifies passes none
     * @param ?\Closure                    $nonceOf  fn(string $address): int, the account's next sequence number
     *                                               from a node; needed to pay
     * @param ClockInterface|\Closure|null $clock    Returns the current Unix time; tests pin it
     * @param ?string                      $feePayer The facilitator's sponsor account, for the offers this
     *                                               seller builds (extra.feePayer)
     */
    public function __construct(
        #[\SensitiveParameter] private readonly ?array $buyer = null,
        private readonly ?\Closure $nonceOf = null,
        private readonly ClockInterface|\Closure|null $clock = null,
        private readonly ?string $feePayer = null,
    ) {
    }

    public function supports(string $network): bool
    {
        return self::TESTNET_NETWORK === $network || self::MAINNET_NETWORK === $network;
    }

    public function pay(array $required, array $req): array
    {
        $buyer = $this->buyer ?? throw new \LogicException('Paying needs a buyer account.');
        $nonceOf = $this->nonceOf ?? throw new \LogicException('Paying needs a way to read the nonce.');
        $extra = $req['extra'] ?? null;
        $feePayer = is_array($extra) ? ($extra['feePayer'] ?? null) : null;
        if (!is_string($feePayer) || !Concordium::isAddress($feePayer) || $feePayer === ($buyer['address'] ?? null)) {
            throw new \InvalidArgumentException('A Concordium offer names the facilitator as extra.feePayer (a base58check account address).');
        }
        if (!Concordium::isAddress((string) ($buyer['address'] ?? '')) || !preg_match('/^[0-9a-f]{64}$/', (string) ($buyer['signKey'] ?? ''))) {
            throw new \InvalidArgumentException('A Concordium buyer is an account address and a 32-byte Ed25519 seed (hex).');
        }
        if (!Concordium::isAddress((string) ($req['payTo'] ?? '')) || 'CCD' !== ($req['asset'] ?? null)) {
            throw new \InvalidArgumentException('A Concordium offer names a base58check payTo and the native CCD asset.');
        }
        if (!Field::is(Field::AMOUNT, $req['amount'] ?? null)) {
            throw new \InvalidArgumentException('A Concordium offer needs a positive integer amount in microCCD.');
        }
        $timeout = $req['maxTimeoutSeconds'] ?? null;
        if (!self::validTimeout($timeout)) {
            throw new \InvalidArgumentException('A Concordium offer lives between 1 and '.self::MAX_TIMEOUT.' s: '.var_export($timeout, true).'.');
        }
        $tx = [
            'version' => 1,
            'header' => [
                'sender' => $buyer['address'],
                'nonce' => $nonceOf($buyer['address']),
                'expiry' => $this->now() + $timeout,
                'executionEnergyAmount' => Concordium::TRANSFER_ENERGY,
                'numSignatures' => 1,
                'sponsor' => ['address' => $feePayer, 'numSignatures' => 1],
            ],
            'payload' => ['type' => 'transfer', 'toAddress' => $req['payTo'], 'amount' => (string) $req['amount']],
            'signatures' => ['sender' => [], 'sponsor' => []],
        ];
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin((string) $buyer['signKey'])));
        $tx['signatures']['sender'] = ['0' => ['0' => bin2hex(sodium_crypto_sign_detached(Concordium::signHash($tx), $secret))]];

        return [
            'x402Version' => 2,
            'resource' => $required['resource'] ?? null,
            'accepted' => $req,
            'payload' => ['signedTransaction' => $tx],
        ];
    }

    public function verify(array $payment, array $req): ?string
    {
        $tx = $payment['payload']['signedTransaction'] ?? null;
        $accepted = $payment['accepted'] ?? null;
        if (!is_array($tx) || !is_array($accepted)) {
            return 'invalid_payload';
        }
        $h = $tx['header'] ?? null;
        $p = $tx['payload'] ?? null;
        $sig = $tx['signatures']['sender']['0']['0'] ?? null;
        $extra = $req['extra'] ?? null;
        $feePayer = is_array($extra) ? ($extra['feePayer'] ?? null) : null;
        $sponsorField = is_array($h) ? ($h['sponsor'] ?? null) : null;
        if (!is_array($h) || !is_array($p) || !is_array($sponsorField) || !is_string($sig) || !is_string($feePayer)
            || array_key_exists('address', $sponsorField) === array_key_exists('account', $sponsorField)
            || !Concordium::isAddress($feePayer)) {
            return 'invalid_payload';
        }
        $now = $this->now();
        $sponsor = array_key_exists('address', $sponsorField) ? $sponsorField['address'] : $sponsorField['account'];
        // Strings and ints both appear in SDK JSON; the digest and these checks read the same text.
        $same = static fn (mixed $a, mixed $b): bool => (is_string($a) || is_int($a)) && (string) $a === (string) $b;

        // Reason codes: the x402 v2 spec's §9 where it has one.
        return match (true) {
            ($payment['x402Version'] ?? null) !== 2 => 'invalid_x402_version',
            ($accepted['scheme'] ?? null) !== 'exact' => 'invalid_scheme',
            ($accepted['network'] ?? null) !== $req['network'] || !$this->supports((string) ($req['network'] ?? '')) => 'invalid_network',
            !Field::is(Field::AMOUNT, $req['amount'] ?? null) => 'invalid_payment_requirements',
            'CCD' !== ($req['asset'] ?? null) => 'unsupported_asset',
            ($accepted['asset'] ?? null) !== $req['asset'] || ($accepted['payTo'] ?? null) !== $req['payTo'] || !$same($accepted['amount'] ?? null, $req['amount']) => 'invalid_payload',
            1 !== ($tx['version'] ?? null) => 'invalid_exact_concordium_version',
            'transfer' !== ($p['type'] ?? null) || array_diff(array_keys($p), ['type', 'toAddress', 'amount']) => 'invalid_exact_concordium_payload_type',
            $sponsor !== $feePayer => 'invalid_exact_concordium_payload_sponsor_mismatch',
            ($p['toAddress'] ?? null) !== $req['payTo'] => 'invalid_exact_concordium_payload_recipient_mismatch',
            !$same($p['amount'] ?? null, $req['amount']) => 'invalid_exact_concordium_payload_amount_mismatch',
            // The sponsor pays the fee and cannot be a party: no draining it by paying itself.
            ($h['sender'] ?? null) === $feePayer || $req['payTo'] === $feePayer => 'invalid_exact_concordium_payload_sponsor_is_party',
            // What the sponsor will pay for is bounded here: a plain transfer, one signature each.
            !array_key_exists('executionEnergyAmount', $h) || Concordium::TRANSFER_ENERGY !== $h['executionEnergyAmount']
              || !array_key_exists('numSignatures', $h) || 1 !== $h['numSignatures']
              || !array_key_exists('numSignatures', $sponsorField) || 1 !== $sponsorField['numSignatures']
              || !isset($tx['signatures']['sponsor']) || [] !== $tx['signatures']['sponsor'] => 'invalid_exact_concordium_payload_energy',
            !is_int($h['expiry'] ?? null) || $h['expiry'] < $now + self::SLACK => 'invalid_exact_concordium_payload_expiry',
            $h['expiry'] > $now + (int) ($req['maxTimeoutSeconds'] ?? 0) => 'invalid_exact_concordium_payload_expiry',
            !preg_match('/^[0-9a-f]{128}$/', $sig) => 'invalid_exact_concordium_payload_signature',
            default => $this->wellFormed($tx),
        };
    }

    public function payer(array $payment): ?string
    {
        $sender = $payment['payload']['signedTransaction']['header']['sender'] ?? null;

        return is_string($sender) ? $sender : null;
    }

    /**
     * Network, payer and nonce: the same sponsored transfer must not buy twice.
     */
    public function spent(array $payment): ?array
    {
        $h = $payment['payload']['signedTransaction']['header'] ?? null;

        return is_array($h) ? [$payment['accepted']['network'] ?? '', (string) ($h['sender'] ?? ''), (string) ($h['nonce'] ?? '')] : null;
    }

    public function expiresAt(array $payment): ?int
    {
        $until = $payment['payload']['signedTransaction']['header']['expiry'] ?? null;

        return is_int($until) ? $until : (is_numeric($until) ? (int) $until : null);
    }

    public function tooShortFor(array $payment, int $seconds): ?string
    {
        // The same margin verify() asks for, so it does not expire on its way into a block.
        return (int) ($this->expiresAt($payment) ?? 0) - $this->now() < $seconds + self::SLACK ? 'invalid_exact_concordium_payload_expiry' : null;
    }

    public function valid(string $address): bool
    {
        return Concordium::isAddress($address);
    }

    public function spell(string $address): string
    {
        return $address;
    }

    public function same(string $a, string $b): bool
    {
        return $a === $b;
    }

    public function signable(array $req, array $token): bool
    {
        $extra = $req['extra'] ?? null;
        $feePayer = is_array($extra) ? ($extra['feePayer'] ?? null) : null;
        $buyerAddress = null === $this->buyer ? null : ($this->buyer['address'] ?? null);

        return is_string($req['payTo'] ?? null) && Concordium::isAddress($req['payTo'])
            && is_string($feePayer) && Concordium::isAddress($feePayer) && $feePayer !== $req['payTo'] && $feePayer !== $buyerAddress
            && 'CCD' === ($req['asset'] ?? null)
            && Field::is(Field::AMOUNT, $req['amount'] ?? null)
            && self::validTimeout($req['maxTimeoutSeconds'] ?? null);
    }

    /**
     * A Concordium offer is priced in CCD (not in dollars: a dollar price would
     * be charged as if CCD were worth one) and names the facilitator that will
     * sponsor it.
     */
    public function offerExtra(string $network, int $timeout, bool $inToken): array
    {
        if (!$inToken) {
            throw new \InvalidArgumentException("CCD on $network is not priced in dollars: use Networks::requirementsInToken().");
        }
        if (!self::validTimeout($timeout)) {
            throw new \InvalidArgumentException('A Concordium offer lives at most '.self::MAX_TIMEOUT." s, so a replay stays inside the claim: $timeout.");
        }
        if (null === $this->feePayer || !Concordium::isAddress($this->feePayer)) {
            throw new \InvalidArgumentException('A Concordium offer names the facilitator as extra.feePayer: build the scheme with that account.');
        }

        return ['feePayer' => $this->feePayer, 'paymentFlow' => 'upfront'];
    }

    public static function validTimeout(mixed $seconds): bool
    {
        return is_int($seconds) && $seconds >= 1 && $seconds <= self::MAX_TIMEOUT;
    }

    /**
     * Addresses and numbers that the digest can read; NULL when they are.
     */
    private function wellFormed(array $tx): ?string
    {
        try {
            Concordium::signHash($tx);

            return null;
        } catch (InvalidPaymentException) {
            return 'invalid_payload';
        }
    }

    private function now(): int
    {
        return Clock::now($this->clock);
    }
}
