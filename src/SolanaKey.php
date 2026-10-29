<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * A Solana wallet's key (ed25519): its address, and what it signs.
 */
final class SolanaKey
{
    /**
     * The 64-byte secret key: seed and public key.
     */
    private readonly string $secret;

    /**
     * Reads a key as wallets export it.
     *
     * @param string $key
     *                    A JSON array of 64 bytes (solana-keygen), or base58 of the 64-byte
     *                    secret key (Phantom) or of its 32-byte seed
     *
     * @throws \InvalidArgumentException
     *                                   Not one of those
     */
    public function __construct(string $key)
    {
        $key = trim($key);
        $bytes = str_starts_with($key, '[')
          ? implode('', array_map('chr', json_decode($key, true) ?: []))
          : Solana::decode($key);
        $this->secret = match (strlen($bytes)) {
            64 => $bytes,
            32 => sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($bytes)),
            default => throw new \InvalidArgumentException('A Solana key is 64 bytes (or a 32-byte seed), as a JSON array or base58.'),
        };
    }

    /**
     * The address, in base58.
     */
    public function address(): string
    {
        return Solana::encode(substr($this->secret, 32));
    }

    /**
     * The ed25519 signature of a message: 64 bytes.
     */
    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secret);
    }
}
