<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The Facilitator-Seller-Proof header for Circle's keyless mode.
 *
 * An EIP-712 signature by the payTo key over the call's purpose, method and
 * exact body, so Circle knows the caller controls payTo.
 *
 * @see https://developers.circle.com/facilitator-service/sign-seller-proof
 */
final class SellerProof
{
    private const TYPES = [
        'SellerRequest' => [
            ['name' => 'purpose', 'type' => 'string'],
            ['name' => 'method', 'type' => 'string'],
            ['name' => 'bodyHash', 'type' => 'bytes32'],
            ['name' => 'network', 'type' => 'string'],
            ['name' => 'payTo', 'type' => 'address'],
            ['name' => 'nonce', 'type' => 'bytes32'],
            ['name' => 'issuedAt', 'type' => 'uint64'],
            ['name' => 'expiresAt', 'type' => 'uint64'],
        ],
    ];

    /**
     * Builds the header value (base64url JSON envelope).
     *
     * @param Signer      $seller
     *                              The payTo key
     * @param string      $purpose
     *                              One of verify, settle or status: must match the route called
     * @param string      $method
     *                              The HTTP method of the call
     * @param string      $body
     *                              The exact body sent: its hash is signed
     * @param string      $network
     *                              A CAIP-2 id, e.g. eip155:5042002. Its chain id is the domain's.
     * @param string|null $nonce
     *                              Thirty-two random bytes as 0x-hex; generated when omitted (tests pass
     *                              one)
     * @param int|null    $issuedAt
     *                              Unix time; now when omitted. The proof lives 5 minutes, Circle's max.
     */
    public static function header(Signer $seller, string $purpose, string $method, string $body, string $network, ?string $nonce = null, ?int $issuedAt = null): string
    {
        if (!preg_match('/^eip155:(\d+)$/', $network, $m)) {
            throw new \InvalidArgumentException("Not an EVM network: $network.");
        }
        $nonce ??= '0x'.bin2hex(random_bytes(32));
        $issuedAt ??= time();
        $message = [
            'purpose' => $purpose,
            'method' => strtoupper($method),
            'bodyHash' => Eip712::keccak($body),
            'network' => $network,
            'payTo' => $seller->address(),
            'nonce' => $nonce,
            'issuedAt' => $issuedAt,
            'expiresAt' => $issuedAt + 300,
        ];
        $digest = Eip712::hash(
            ['name' => 'Circle Facilitator Seller Request', 'version' => '1', 'chainId' => (int) $m[1]],
            self::TYPES, 'SellerRequest', $message,
        );
        $envelope = [
            'version' => 1,
            'signature' => $seller->sign($digest),
            'network' => $network,
            'payTo' => $message['payTo'],
            'nonce' => $nonce,
            'issuedAt' => $issuedAt,
            'expiresAt' => $message['expiresAt'],
        ];

        return rtrim(strtr(base64_encode(json_encode($envelope, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }
}
