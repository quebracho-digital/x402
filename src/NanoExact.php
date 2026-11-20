<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The `exact` scheme on Nano (`nano:mainnet`): the buyer's wallet sends the exact amount of XNO to `payTo`, and
 * the payment names that send block.
 *
 * Nano has no token contract and no authorization to sign: a send block is the payment, final once the network
 * confirms it, and there is no fee. A block also has no memo, so the send is bound to the payment that names it
 * through its representative field (xno-call-binding, BINDING-STANDARD.md option C): the buyer draws a random
 * 32-byte order id, sets the block's representative to the account whose key is
 * sha256("xno-binding/v1/rep|" ‖ orderId ‖ "|" ‖ payTo ‖ "|" ‖ amount), and sends the order id only to the
 * seller. A block hash is public on the ledger; without the order id behind its representative nobody else can
 * present it as their payment.
 *
 * verify() checks what the payment alone proves; the block itself (confirmed, a send of `amount` raw to `payTo`
 * with that representative, recent) is read from a node by NanoFacilitator. No key, no seed: the library never
 * signs on Nano, the buyer's wallet does ($send). Experimental.
 */
final class NanoExact implements Scheme, SpendsOnce, AddressForm, ShapesOffers
{
    public const string NETWORK = 'nano:mainnet';
    /** What an offer's `asset` says: the chain's own coin, there is no contract. */
    public const string ASSET = 'XNO';
    /** 1 XNO = 10^30 raw, the atomic unit. */
    public const int DECIMALS = 30;
    /**
     * The longest offer timeout, and the oldest block (by the node's receipt time) a payment may name, in seconds:
     * a replay is refused by the claim, which lives Sale::TTL (600 s), and the node's clock may be ahead by SKEW.
     */
    public const int MAX_TIMEOUT = 540;
    /** How far a node's clock may be from ours, in seconds. */
    public const int SKEW = 60;
    /** The prefix of the representative commitment (xno-call-binding v1). */
    public const string COMMITMENT = 'xno-binding/v1/rep|';

    /** An address with a valid checksum, but `xrb_` or anything else where `nano_` is the one spelling. */
    private const string ADDRESS = '/\A(?:nano|xrb)_[13][13456789abcdefghijkmnopqrstuwxyz]{59}\z/';
    private const string ALPHABET = '13456789abcdefghijkmnopqrstuwxyz';
    /** A block hash as nodes write it: 64 upper-case hex digits. */
    private const string BLOCK = '/\A[0-9A-F]{64}\z/';
    /** An order id as the buyer writes it: 64 lower-case hex digits. */
    private const string ORDER_ID = '/\A[0-9a-f]{64}\z/';
    /** A raw amount: above zero, at most 39 digits (the supply is below 2^128 raw). */
    private const string RAW = '/\A[1-9][0-9]{0,38}\z/';

    /**
     * @param ?\Closure $send
     *                           The buyer's wallet: fn (string $payTo, string $raw, string $representative):
     *                           array{hash: string, account: string}. It sends $raw raw to $payTo with that
     *                           representative, waits for the confirmation, and returns the block hash and its own
     *                           account. Without it, pay() throws: this side can only verify
     * @param ?\Closure $orderId
     *                           Returns 32 random bytes; tests pin it
     */
    public function __construct(
        private readonly ?\Closure $send = null,
        private readonly ?\Closure $orderId = null,
    ) {
    }

    public function supports(string $network): bool
    {
        return self::NETWORK === $network;
    }

    public function pay(array $required, array $req): array
    {
        $send = $this->send ?? throw new \LogicException('Paying on Nano is a send from the buyer\'s wallet: pass $send.');
        if (null !== self::badOffer($req)) {
            throw new \InvalidArgumentException('A Nano offer is nano:mainnet, asset XNO, an amount in raw, a nano_ payTo with a valid checksum and maxTimeoutSeconds between 1 and '.self::MAX_TIMEOUT.'.');
        }
        $orderId = bin2hex($this->orderId ? ($this->orderId)() : random_bytes(32));
        if (1 !== preg_match(self::ORDER_ID, $orderId)) {
            throw new \LogicException('An order id is 32 bytes.');
        }
        $sent = $send($req['payTo'], $req['amount'], self::representative($orderId, $req['payTo'], $req['amount']));
        $hash = \is_array($sent) ? $sent['hash'] ?? null : null;
        $account = \is_array($sent) ? $sent['account'] ?? null : null;
        if (!\is_string($hash) || 1 !== preg_match(self::BLOCK, strtoupper($hash)) || !\is_string($account) || !$this->valid($account)) {
            throw new \LogicException('$send returns the block hash (64 hex) and the sending account.');
        }

        return [
            'x402Version' => 2,
            'resource' => $required['resource'] ?? null,
            'accepted' => $req,
            'payload' => ['block' => strtoupper($hash), 'account' => $this->spell($account), 'orderId' => $orderId],
        ];
    }

    public function verify(array $payment, array $req): ?string
    {
        $payload = $payment['payload'] ?? null;
        $accepted = $payment['accepted'] ?? null;

        return match (true) {
            !\is_array($payload) || !\is_array($accepted) => 'invalid_payload',
            // One spelling per value: the claim key (Settlement::key) must not see one block as several payments.
            [] !== array_diff(array_keys($payload), ['block', 'account', 'orderId']) => 'invalid_payload',
            !\is_string($payload['block'] ?? null) || 1 !== preg_match(self::BLOCK, $payload['block']) => 'invalid_payload',
            !\is_string($payload['orderId'] ?? null) || 1 !== preg_match(self::ORDER_ID, $payload['orderId']) => 'invalid_payload',
            !\is_string($payload['account'] ?? null) || !$this->valid($payload['account']) || $this->spell($payload['account']) !== $payload['account'] => 'invalid_payload',
            ($payment['x402Version'] ?? null) !== 2 => 'invalid_x402_version',
            ($accepted['scheme'] ?? null) !== 'exact' => 'invalid_scheme',
            ($accepted['network'] ?? null) !== ($req['network'] ?? null) || !$this->supports((string) ($req['network'] ?? '')) => 'invalid_network',
            null !== self::badOffer($req) => 'invalid_payment_requirements',
            ($accepted['asset'] ?? null) !== $req['asset']
              || ($accepted['payTo'] ?? null) !== $req['payTo']
              || ($accepted['amount'] ?? null) !== $req['amount'] => 'invalid_payload',
            default => null,
        };
    }

    /**
     * The account the payment says sent the block. NanoFacilitator refuses a block another account sent.
     */
    public function payer(array $payment): ?string
    {
        $account = $payment['payload']['account'] ?? null;

        return \is_string($account) && $this->valid($account) ? $this->spell($account) : null;
    }

    /**
     * Network and block hash: a block is spent once, whatever order id comes with it.
     */
    public function spent(array $payment): ?array
    {
        $block = $payment['payload']['block'] ?? null;

        return \is_string($block) ? [$payment['accepted']['network'] ?? '', strtoupper($block)] : null;
    }

    /**
     * A Nano offer is priced in XNO (not worth a dollar), lives at most MAX_TIMEOUT seconds and is settled
     * upfront: the facilitator only reads a block that already moved, so the handler runs after it did.
     */
    public function offerExtra(string $network, int $timeout, bool $inToken): array
    {
        if (!$inToken) {
            throw new \InvalidArgumentException("XNO on $network is not priced in dollars: use Networks::requirementsInToken().");
        }
        if (!self::validTimeout($timeout)) {
            throw new \InvalidArgumentException('A Nano offer lives at most '.self::MAX_TIMEOUT." s, so a replay stays inside the claim: $timeout.");
        }

        return ['paymentFlow' => 'upfront'];
    }

    /**
     * A `nano_` or `xrb_` address whose checksum matches its key.
     */
    public function valid(string $address): bool
    {
        return null !== self::publicKey($address);
    }

    /**
     * The `nano_` spelling of an address (`xrb_` is the older prefix of the same account).
     */
    public function spell(string $address): string
    {
        return str_starts_with($address, 'xrb_') ? 'nano_'.substr($address, 4) : $address;
    }

    /**
     * Two addresses of one key, or two spellings of one block hash (hex in any case).
     */
    public function same(string $a, string $b): bool
    {
        $key = self::publicKey($a);
        if (null !== $key) {
            return $key === self::publicKey($b);
        }

        return 1 === preg_match('/\A[0-9a-fA-F]{64}\z/', $a) && 0 === strcasecmp($a, $b);
    }

    /**
     * The 32-byte public key of an address, or NULL when it is malformed or its checksum does not match.
     */
    public static function publicKey(string $address): ?string
    {
        if (1 !== preg_match(self::ADDRESS, $address)) {
            return null;
        }
        $bits = '';
        foreach (str_split(substr($address, strpos($address, '_') + 1)) as $character) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $character)), 5, '0', \STR_PAD_LEFT);
        }
        // 60 characters carry 300 bits: 4 bits of padding, the 256-bit key, the 40-bit checksum.
        $key = self::bytes(substr($bits, 4, 256));

        return hash_equals(strrev(Blake2b::hash($key, 5)), self::bytes(substr($bits, 260, 40))) ? $key : null;
    }

    /**
     * The `nano_` address of a 32-byte public key.
     */
    public static function account(string $publicKey): string
    {
        if (32 !== \strlen($publicKey)) {
            throw new \InvalidArgumentException('A Nano public key is 32 bytes.');
        }
        $bits = '0000';
        foreach (str_split($publicKey.strrev(Blake2b::hash($publicKey, 5))) as $byte) {
            $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
        }
        $address = 'nano_';
        foreach (str_split($bits, 5) as $chunk) {
            $address .= self::ALPHABET[(int) bindec($chunk)];
        }

        return $address;
    }

    /**
     * The representative a send for this order carries: the account of sha256(COMMITMENT ‖ orderId ‖ "|" ‖ payTo
     * ‖ "|" ‖ amount), as xno-call-binding's commitment_rep_account() computes it.
     *
     * @param string $orderId the buyer's order id, 64 lower-case hex
     * @param string $payTo   the offer's payTo, as the offer spells it
     * @param string $amount  the offer's amount in raw, as a decimal string
     */
    public static function representative(string $orderId, string $payTo, string $amount): string
    {
        return self::account(hash('sha256', self::COMMITMENT.$orderId.'|'.$payTo.'|'.$amount, true));
    }

    /**
     * Whether an offer's timeout is an int the claim outlives.
     */
    public static function validTimeout(mixed $seconds): bool
    {
        return \is_int($seconds) && $seconds >= 1 && $seconds <= self::MAX_TIMEOUT;
    }

    /**
     * Why $req is no Nano offer, or NULL when it is one.
     */
    private static function badOffer(array $req): ?string
    {
        $payTo = $req['payTo'] ?? null;

        return match (true) {
            self::NETWORK !== ($req['network'] ?? null) => 'network',
            self::ASSET !== ($req['asset'] ?? null) => 'asset',
            !\is_string($req['amount'] ?? null) || 1 !== preg_match(self::RAW, $req['amount']) => 'amount',
            !\is_string($payTo) || null === self::publicKey($payTo) || !str_starts_with($payTo, 'nano_') => 'payTo',
            !self::validTimeout($req['maxTimeoutSeconds'] ?? null) => 'maxTimeoutSeconds',
            default => null,
        };
    }

    private static function bytes(string $bits): string
    {
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            $bytes .= \chr((int) bindec($byte));
        }

        return $bytes;
    }
}
