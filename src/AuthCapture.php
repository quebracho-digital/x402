<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The pieces of the `auth-capture` scheme on EVM that buyer, seller and operator share: the escrow's
 * deployments, its PaymentInfo struct, the hashes that identify a payment, and the calls the escrow takes.
 *
 * Spec: x402-foundation/x402, specs/schemes/auth-capture/scheme_auth_capture_evm.md (v1.1, 2026-08-26).
 * Contracts: base/commerce-payments v1.1.0 (AuthCaptureEscrow, ERC3009PaymentCollector), CREATE2, the same
 * addresses on every chain they are deployed on (Base and Base Sepolia, per their README, 2026-11-08).
 *
 * The money path, in one paragraph: the buyer signs one EIP-3009 ReceiveWithAuthorization of the whole
 * amount to the escrow's token collector, with a nonce that hashes the PaymentInfo (so the signature is
 * good for this one payment and nothing else). The operator sends `authorize`: the collector pulls the
 * tokens into the escrow, where they are held. Later the operator sends `capture` (pays the receiver,
 * repeatable up to the hold) or `void` (gives the rest back). If nobody does before `authorizationExpiry`,
 * the buyer calls `reclaim` itself: a seller that disappears cannot keep the money.
 */
final class AuthCapture
{
    public const SCHEME = 'auth-capture';

    /** The commerce-payments v1.1 set (the spec's default): escrow, EIP-3009 collector, Permit2 collector, refund collector. */
    public const V1_1 = [
        'escrow' => '0xf96815976523E00e65Be8f34cA5e64b4f41EB19c',
        'eip3009Collector' => '0x8612dfdc421f80336cd14E8EF9cb1E765dB5ab88',
        'permit2Collector' => '0xD69831Aed5bfe262067ec4c751f4F830EcdD446e',
        'refundCollector' => '0x7a03443724d14798c4AB4622F1DAAcA761Fea486',
    ];

    /** The v1.0 set. Its `capture` and `charge` take a uint16 feeBps instead of a feeAmount: we pay into it, we do not operate it. */
    public const V1_0 = [
        'escrow' => '0xBdEA0D1bcC5966192B070Fdf62aB4EF5b4420cff',
        'eip3009Collector' => '0x0E3dF9510de65469C4518D7843919c0b8C7A7757',
        'permit2Collector' => '0x992476B9Ee81d52a5BdA0622C333938D0Af0aB26',
        'refundCollector' => '0x934907bffd0901b6A21e398B9C53A4A38F02fa5d',
    ];

    public const ZERO = '0x0000000000000000000000000000000000000000';

    /** The struct as AuthCaptureEscrow hashes it (PAYMENT_INFO_TYPEHASH). Order matters: it is the ABI tuple too. */
    public const PAYMENT_INFO = 'PaymentInfo(address operator,address payer,address receiver,address token,uint120 maxAmount,uint48 preApprovalExpiry,uint48 authorizationExpiry,uint48 refundExpiry,uint16 minFeeBps,uint16 maxFeeBps,address feeReceiver,uint256 salt)';

    /** The domain tag of a bound salt (a receiver authorizer or a policy committed into it). */
    public const SALT_BINDING = 'x402AuthCaptureSaltBinding(address receiverAuthorizer,address policy,uint256 saltNonce)';

    private const FIELDS = ['operator' => 'address', 'payer' => 'address', 'receiver' => 'address', 'token' => 'address', 'maxAmount' => 'uint', 'preApprovalExpiry' => 'uint', 'authorizationExpiry' => 'uint', 'refundExpiry' => 'uint', 'minFeeBps' => 'uint', 'maxFeeBps' => 'uint', 'feeReceiver' => 'address', 'salt' => 'uint'];

    private const TUPLE = '(address,address,address,address,uint120,uint48,uint48,uint48,uint16,uint16,address,uint256)';

    /** What the buyer signs: the collector, not the seller, receives it (the escrow then holds it). */
    public const TYPES = [
        'ReceiveWithAuthorization' => [
            ['name' => 'from', 'type' => 'address'],
            ['name' => 'to', 'type' => 'address'],
            ['name' => 'value', 'type' => 'uint256'],
            ['name' => 'validAfter', 'type' => 'uint256'],
            ['name' => 'validBefore', 'type' => 'uint256'],
            ['name' => 'nonce', 'type' => 'bytes32'],
        ],
    ];

    /**
     * The deployment an `extra.authCaptureEscrow` selects: absent is v1.1; anything but the two escrows is NULL.
     *
     * @return ?array{escrow: string, eip3009Collector: string, permit2Collector: string, refundCollector: string}
     */
    public static function deployment(mixed $escrow): ?array
    {
        return match (true) {
            null === $escrow => self::V1_1,
            !\is_string($escrow) => null,
            0 === strcasecmp($escrow, self::V1_1['escrow']) => self::V1_1,
            0 === strcasecmp($escrow, self::V1_0['escrow']) => self::V1_0,
            default => null,
        };
    }

    /**
     * The PaymentInfo a requirement and a payload describe (the spec's appendix, field by field).
     *
     * @param string $salt              PaymentInfo.salt, 0x + 64 hex (payload.salt)
     * @param int    $preApprovalExpiry the authorization's validBefore
     *
     * @return array<string, string|int>
     */
    public static function paymentInfo(array $req, string $payer, string $salt, int $preApprovalExpiry): array
    {
        $extra = $req['extra'] ?? [];

        return [
            'operator' => (string) ($extra['captureAuthorizer'] ?? ''),
            'payer' => $payer,
            'receiver' => (string) $req['payTo'],
            'token' => (string) $req['asset'],
            'maxAmount' => (string) $req['amount'],
            'preApprovalExpiry' => $preApprovalExpiry,
            'authorizationExpiry' => (int) ($extra['captureDeadline'] ?? 0),
            'refundExpiry' => (int) ($extra['refundDeadline'] ?? 0),
            'minFeeBps' => (int) ($extra['minFeeBps'] ?? 0),
            'maxFeeBps' => (int) ($extra['maxFeeBps'] ?? 0),
            'feeReceiver' => (string) ($extra['feeRecipient'] ?? self::ZERO),
            'salt' => $salt,
        ];
    }

    /**
     * AuthCaptureEscrow.getHash(paymentInfo): the escrow's key for the payment (paymentState), committed to
     * the chain and the escrow.
     */
    public static function hash(int $chainId, array $info, string $escrow = self::V1_1['escrow']): string
    {
        $inner = Eip712::keccak(Eip712::raw(Eip712::keccak(self::PAYMENT_INFO)).Eip712::raw(self::tuple($info)));

        return Eip712::keccak(Eip712::raw(Rpc::word(dechex($chainId)).Rpc::word($escrow).Rpc::word($inner)));
    }

    /**
     * The EIP-3009 nonce the buyer signs: the hash with the payer zeroed (the collector recomputes it so).
     */
    public static function nonce(int $chainId, array $info, string $escrow = self::V1_1['escrow']): string
    {
        return self::hash($chainId, ['payer' => self::ZERO] + $info, $escrow);
    }

    /**
     * A bound salt: keccak(abi.encode(SALT_BINDING_TYPEHASH, receiverAuthorizer, policy, saltNonce)).
     */
    public static function boundSalt(string $receiverAuthorizer, string $policy, string $saltNonce): string
    {
        return Eip712::keccak(Eip712::raw(Eip712::keccak(self::SALT_BINDING).Rpc::word($receiverAuthorizer).Rpc::word($policy).Rpc::word($saltNonce)));
    }

    /**
     * Whether a requirement turns the salt binding on (a non-zero receiverAuthorizer or policy).
     */
    public static function bound(array $req): bool
    {
        foreach (['receiverAuthorizer', 'policy'] as $field) {
            $address = $req['extra'][$field] ?? null;
            if (null !== $address && 0 !== strcasecmp((string) $address, self::ZERO)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The EIP-712 digest of the buyer's ReceiveWithAuthorization against the requirement's token.
     */
    public static function digest(array $req, array $authorization): string
    {
        return Eip712::hash(
            [
                'name' => $req['extra']['name'],
                'version' => $req['extra']['version'],
                'chainId' => self::chainId($req['network']),
                'verifyingContract' => $req['asset'],
            ],
            self::TYPES,
            'ReceiveWithAuthorization',
            $authorization,
        );
    }

    public static function chainId(string $network): int
    {
        return (int) explode(':', $network)[1];
    }

    /**
     * authorize(PaymentInfo, amount, tokenCollector, collectorData): collects the whole hold.
     */
    public static function authorizeCall(array $info, string $amount, string $collector, string $collectorData): string
    {
        $data = Eip712::raw($collectorData);
        $head = self::tuple($info).self::uint($amount).Rpc::word($collector).Rpc::word(dechex(15 * 32));
        $tail = Rpc::word(dechex(\strlen($data))).str_pad(bin2hex($data), (int) ceil(\strlen($data) / 32) * 64, '0');

        return self::selector('authorize('.self::TUPLE.',uint256,address,bytes)').$head.$tail;
    }

    /**
     * capture(PaymentInfo, amount, feeAmount, feeReceiver) on the v1.1 escrow.
     */
    public static function captureCall(array $info, string $amount, string $feeAmount, string $feeReceiver): string
    {
        return self::selector('capture('.self::TUPLE.',uint256,uint256,address)').self::tuple($info).self::uint($amount).self::uint($feeAmount).Rpc::word($feeReceiver);
    }

    /**
     * void(PaymentInfo): gives the whole remaining hold back to the payer.
     */
    public static function voidCall(array $info): string
    {
        return self::selector('void('.self::TUPLE.')').self::tuple($info);
    }

    /**
     * paymentState(bytes32): (hasCollectedPayment, capturableAmount, refundableAmount).
     */
    public static function stateCall(string $paymentInfoHash): string
    {
        return self::selector('paymentState(bytes32)').Rpc::word($paymentInfoHash);
    }

    /**
     * The answer to stateCall(), read.
     *
     * @return array{collected: bool, capturable: string, refundable: string}
     *
     * @throws \UnexpectedValueException not three words
     */
    public static function state(string $answer): array
    {
        $hex = str_starts_with($answer, '0x') ? substr($answer, 2) : $answer;
        if (!preg_match('/^[0-9a-fA-F]{192}$/', $hex)) {
            throw new \UnexpectedValueException('paymentState did not answer three words.');
        }

        return [
            'collected' => '0' !== gmp_strval(gmp_init(substr($hex, 0, 64), 16)),
            'capturable' => gmp_strval(gmp_init(substr($hex, 64, 64), 16)),
            'refundable' => gmp_strval(gmp_init(substr($hex, 128, 64), 16)),
        ];
    }

    /**
     * The fee a capture of $amount takes at the published (fixed) rate: amount × minFeeBps / 10000, the
     * escrow's own integer division.
     */
    public static function fee(array $info, string $amount): string
    {
        return gmp_strval(gmp_div_q(gmp_mul(gmp_init($amount, 10), (int) $info['minFeeBps']), 10000));
    }

    /**
     * PaymentInfo as its ABI tuple: twelve static words, hex without 0x.
     */
    public static function tuple(array $info): string
    {
        $words = '';
        foreach (self::FIELDS as $field => $type) {
            $value = $info[$field] ?? throw new \InvalidArgumentException("PaymentInfo needs $field.");
            $words .= 'address' === $type ? Rpc::word((string) $value) : self::uint((string) $value);
        }

        return $words;
    }

    /**
     * An unsigned integer (decimal or 0x-hex) as one ABI word.
     */
    private static function uint(string $value): string
    {
        $n = str_starts_with($value, '0x') ? gmp_init(substr($value, 2), 16) : gmp_init($value, 10);

        return str_pad(gmp_strval($n, 16), 64, '0', \STR_PAD_LEFT);
    }

    private static function selector(string $signature): string
    {
        return substr(Eip712::keccak($signature), 0, 10);
    }
}
