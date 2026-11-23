<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Clock\ClockInterface;

/**
 * The seller's Concordium facilitator: checks a sponsored CCD transfer against
 * the chain, adds the sponsor's signature and waits for ConcordiumBFT
 * finalization (exact scheme, V1 sponsored transactions).
 *
 * The offline rules are ConcordiumExact's; this class adds the spec's
 * chain-side MUSTs: the sender's signature against its on-chain keys, the
 * sequence number, the balance, and a finalized transfer whose amount and
 * recipient are the ones the offer asked for.
 *
 * @see https://github.com/x402-foundation/x402/blob/main/specs/schemes/exact/scheme_exact_concordium.md
 */
final class ConcordiumFacilitator implements Facilitator
{
    private readonly \Closure $clock;

    /**
     * @param string                       $sponsor    the sponsor account the facilitator pays fees from (base58)
     * @param string                       $sponsorKey its Ed25519 seed (32 bytes, hex)
     * @param ClockInterface|\Closure|null $clock      returns the current Unix time; tests pin it
     * @param int                          $pollDelay  seconds between two status queries
     * @param int                          $pollTries  how many status queries at most
     */
    public function __construct(
        private readonly ConcordiumNode $node,
        #[\SensitiveParameter] private readonly string $sponsor,
        #[\SensitiveParameter] private readonly string $sponsorKey,
        ClockInterface|\Closure|null $clock = null,
        private readonly int $pollDelay = 1,
        private readonly int $pollTries = 30,
    ) {
        if (!Concordium::isAddress($sponsor)) {
            throw new \InvalidArgumentException('The sponsor is not a Concordium account address.');
        }
        if (!preg_match('/^[0-9a-f]{64}$/', $sponsorKey)) {
            throw new \InvalidArgumentException('The sponsor key is a 32-byte Ed25519 seed in lower-case hex.');
        }
        if ($pollDelay < 0 || $pollTries < 0) {
            throw new \InvalidArgumentException('The poll delay and tries are zero or more.');
        }
        $this->clock = static fn (): int => Clock::now($clock);
    }

    public function verify(array $payment, array $req): array
    {
        $scheme = new ConcordiumExact(clock: $this->clock);
        $reason = $scheme->verify($payment, $req);
        if (null !== $reason) {
            return ['isValid' => false, 'invalidReason' => $reason, 'payer' => null];
        }
        $tx = $payment['payload']['signedTransaction'];
        $payer = $tx['header']['sender'];
        $extra = $req['extra'] ?? null;
        $feePayer = is_array($extra) ? ($extra['feePayer'] ?? null) : null;
        if ($feePayer !== $this->sponsor) {
            return ['isValid' => false, 'invalidReason' => 'invalid_exact_concordium_sponsor_mismatch', 'payer' => $payer];
        }
        $info = $this->node->accountInfo($payer);
        if (null === $info) {
            return ['isValid' => false, 'invalidReason' => 'invalid_exact_concordium_sender_unknown', 'payer' => $payer];
        }
        $nextNonce = $this->node->nextNonce($payer);
        $parts = Concordium::parts($tx);
        if ($parts['nonce'] !== $nextNonce) {
            return ['isValid' => false, 'invalidReason' => 'invalid_exact_concordium_nonce', 'payer' => $payer];
        }
        if ($info['available'] < $parts['amount']) {
            return ['isValid' => false, 'invalidReason' => 'invalid_exact_concordium_balance', 'payer' => $payer];
        }
        if (!$this->signedByTheAccount($tx, $info)) {
            return ['isValid' => false, 'invalidReason' => 'invalid_exact_concordium_signature', 'payer' => $payer];
        }

        return ['isValid' => true, 'payer' => $payer];
    }

    public function settle(array $payment, array $req): array
    {
        $network = (string) ($req['network'] ?? '');
        $checked = $this->verify($payment, $req);
        if (true !== $checked['isValid']) {
            return ['success' => false, 'errorReason' => $checked['invalidReason'] ?? 'invalid_payment', 'transaction' => '', 'network' => $network, 'payer' => $checked['payer'] ?? null];
        }
        $tx = $payment['payload']['signedTransaction'];
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin($this->sponsorKey)));
        $tx['signatures']['sponsor'] = ['0' => ['0' => bin2hex(sodium_crypto_sign_detached(Concordium::signHash($tx), $secret))]];
        try {
            $hash = $this->node->send($tx);
        } catch (GrpcException $e) {
            return ['success' => false, 'errorReason' => 'settlement_rejected', 'transaction' => '', 'network' => $network, 'payer' => $checked['payer']];
        }
        for ($i = 0; $i < $this->pollTries; ++$i) {
            if ($i > 0) {
                sleep($this->pollDelay);
            }
            try {
                $status = $this->node->status($hash);
            } catch (FacilitatorException) {
                // Settled or not, the hash is what reconciles it.
                break;
            }
            if (true !== $status['finalized']) {
                continue;
            }
            if (true !== $status['success']) {
                return ['success' => false, 'errorReason' => 'settlement_failed', 'transaction' => $hash, 'network' => $network, 'payer' => $checked['payer']];
            }
            // The answer must be about the transaction we sent, not another.
            if ($status['transaction'] !== $hash) {
                return ['success' => false, 'errorReason' => 'settlement_mismatch', 'transaction' => $hash, 'network' => $network, 'payer' => $checked['payer']];
            }
            $parts = Concordium::parts($tx);
            if (($status['amount'] ?? null) !== $parts['amount'] || ($status['receiver'] ?? null) !== $req['payTo']) {
                return ['success' => false, 'errorReason' => 'settlement_mismatch', 'transaction' => $hash, 'network' => $network, 'payer' => $checked['payer']];
            }

            return ['success' => true, 'transaction' => $hash, 'network' => $network, 'payer' => $checked['payer']];
        }

        return ['success' => false, 'errorReason' => 'settlement_pending', 'transaction' => $hash, 'network' => $network, 'payer' => $checked['payer']];
    }

    /**
     * The sender's signatures verify against the account's on-chain keys, and
     * meet the credential and account thresholds.
     *
     * @param array{amount: int, available: int, threshold: int, credentials: array<int, array{threshold: int, keys: array<int, string>}>} $info
     */
    private function signedByTheAccount(array $tx, array $info): bool
    {
        $signatures = $tx['signatures']['sender'] ?? null;
        if (!is_array($signatures) || [] === $signatures) {
            return false;
        }
        $count = 0;
        foreach ($signatures as $keys) {
            if (!is_array($keys)) {
                return false;
            }
            $count += count($keys);
        }
        // The scheme's header says one signature; the map must agree with it.
        if (1 !== $count) {
            return false;
        }
        $digest = Concordium::signHash($tx);
        $satisfied = 0;
        foreach ($signatures as $credentialIndex => $keys) {
            $credential = $info['credentials'][(int) $credentialIndex] ?? null;
            if (null === $credential || [] === $keys) {
                return false;
            }
            $valid = 0;
            foreach ($keys as $keyIndex => $signature) {
                $publicKey = $credential['keys'][(int) $keyIndex] ?? null;
                if (!is_string($publicKey) || !is_string($signature) || !preg_match('/^[0-9a-f]{128}$/', $signature)) {
                    return false;
                }
                try {
                    if (sodium_crypto_sign_verify_detached(hex2bin($signature), $digest, hex2bin($publicKey))) {
                        ++$valid;
                    }
                } catch (\SodiumException) {
                    return false;
                }
            }
            if ($valid >= max(1, $credential['threshold'])) {
                ++$satisfied;
            }
        }

        return $satisfied >= max(1, $info['threshold']);
    }
}
