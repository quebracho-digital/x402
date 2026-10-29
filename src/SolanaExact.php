<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The `exact` scheme on Solana: one partially signed v0 transaction with a
 * TransferChecked to the seller's associated token account; the
 * facilitator's fee payer signs last and pays the fees.
 *
 * verify() checks offline what the transaction pays and that the fee payer
 * is only the fee payer (spec §1, §2.1, standard-wallet layout of §3.1):
 * smart wallets and lookup tables are refused, being unreadable offline.
 *
 * @see https://github.com/x402-foundation/x402/blob/main/specs/schemes/exact/scheme_exact_svm.md
 */
final class SolanaExact implements Scheme
{
    // Spec §2.2.1 reference caps.
    private const MAX_COMPUTE_UNITS = 400_000;
    private const MAX_PRICE_MICROLAMPORTS = 5_000_000;
    private const TOKEN_2022 = 'TokenzQdBNbLqP5VEhdkAS6EPFLC1PHnBqCXEpPxuEb';

    /**
     * @param ?SolanaKey $buyer
     *                          Needed to pay; a seller that only verifies passes none
     * @param ?Rpc       $rpc
     *                          The network's node, for a blockhash when the offer suggests none
     */
    public function __construct(
        private readonly ?SolanaKey $buyer = null,
        private readonly ?Rpc $rpc = null,
    ) {
    }

    public function supports(string $network): bool
    {
        return str_starts_with($network, 'solana:');
    }

    public function pay(array $required, array $req): array
    {
        $buyer = $this->buyer ?? throw new \LogicException('Paying needs a buyer key.');

        return [
            'x402Version' => 2,
            'resource' => $required['resource'] ?? null,
            'accepted' => $req,
            'payload' => ['transaction' => Solana::transaction($this->message($req, $buyer->address()), $buyer)],
        ];
    }

    /**
     * The transaction for a browser wallet to sign: base64, both signatures empty.
     */
    public function unsignedTransaction(array $req, string $buyer): string
    {
        return Solana::transaction($this->message($req, $buyer));
    }

    public function verify(array $payment, array $req): ?string
    {
        $accepted = $payment['accepted'] ?? [];
        $wire = is_string($payment['payload']['transaction'] ?? null) ? base64_decode($payment['payload']['transaction'], true) : false;
        $tx = is_string($wire) && strlen($wire) <= 1232 ? Solana::parse($wire) : null;
        $feePayer = $req['extra']['feePayer'] ?? null;
        if (!is_array($accepted) || null === $tx) {
            return 'invalid_payload';
        }
        if (($payment['x402Version'] ?? null) !== 2) {
            return 'invalid_x402_version';
        }
        if (($accepted['scheme'] ?? null) !== 'exact') {
            return 'invalid_scheme';
        }
        if (($accepted['network'] ?? null) !== $req['network']) {
            return 'invalid_network';
        }
        if (!is_string($feePayer)) {
            return 'invalid_payment_requirements';
        }
        if (($accepted['asset'] ?? null) !== $req['asset'] || ($accepted['payTo'] ?? null) !== $req['payTo'] || (string) ($accepted['amount'] ?? '') !== (string) $req['amount']) {
            return 'invalid_payload';
        }
        $keys = $tx['keys'];
        // Two signers, fee payer first; nobody else's signature is needed.
        if (2 !== $tx['signers'] || 2 !== count($tx['signatures']) || ($keys[0] ?? null) !== $feePayer) {
            return 'invalid_exact_svm_payload_transaction_signers';
        }
        $transfers = [];
        foreach ($tx['instructions'] as $ix) {
            $program = $keys[$ix['program']] ?? null;
            foreach ($ix['accounts'] as $a) {
                if (!isset($keys[$a])) {
                    return 'invalid_payload';
                }
            }
            // The fee payer pays fees only: never in an instruction (§2.1.1).
            if (0 === $ix['program'] || in_array(0, $ix['accounts'], true)) {
                return 'invalid_exact_svm_payload_transaction_fee_payer_included_in_instruction_accounts';
            }
            $data = $ix['data'];
            $reason = match ($program) {
                Solana::COMPUTE_BUDGET => match (true) {
                    "\x02" === ($data[0] ?? '') && 5 === strlen($data) => unpack('V', $data, 1)[1] > self::MAX_COMPUTE_UNITS ? 'invalid_exact_svm_payload_transaction_instructions_compute_limit_instruction' : null,
                    "\x03" === ($data[0] ?? '') && 9 === strlen($data) => self::u64($data, 1) > self::MAX_PRICE_MICROLAMPORTS ? 'invalid_exact_svm_payload_transaction_instructions_compute_price_instruction_too_high' : null,
                    default => 'invalid_exact_svm_payload_transaction_instructions',
                },
                Solana::MEMO => isset($req['extra']['memo']) && $data !== $req['extra']['memo'] ? 'invalid_exact_svm_payload_memo_mismatch' : null,
                Solana::TOKEN_PROGRAM, self::TOKEN_2022 => "\x0c" === ($data[0] ?? '') && 10 === strlen($data) && 4 === count($ix['accounts']) ? null : 'invalid_exact_svm_payload_transaction_instructions',
                default => 'invalid_exact_svm_payload_transaction_instructions',
            };
            if (null !== $reason) {
                return $reason;
            }
            if (Solana::TOKEN_PROGRAM === $program || self::TOKEN_2022 === $program) {
                $transfers[] = $ix + ['token' => $program];
            }
        }
        // Exactly one transfer, ours (§1.4).
        if (1 !== count($transfers)) {
            return 'invalid_exact_svm_payload_transaction_instructions_length';
        }
        [$source, $mint, $destination, $authority] = $transfers[0]['accounts'];
        $decimals = Networks::find($req['network'])['decimals'] ?? null;
        $token = $transfers[0]['token'];

        return match (true) {
            $keys[$mint] !== $req['asset'] => 'invalid_exact_svm_payload_mint_mismatch',
            $keys[$destination] !== Solana::ata($req['payTo'], $req['asset'], $token) => 'invalid_exact_svm_payload_recipient_mismatch',
            // The amount is u64; overpaying is tolerated (§1.4), underpaying is not.
            !preg_match('/^[1-9]\d*$/', (string) $req['amount']) || 0 > gmp_cmp(self::u64($transfers[0]['data'], 1), (string) $req['amount']) => 'invalid_exact_svm_payload_amount_insufficient',
            null !== $decimals && ord($transfers[0]['data'][9]) !== $decimals => 'invalid_exact_svm_payload_transaction_instructions',
            // The buyer is the second signer, the transfer's authority, and signed this message.
            1 !== $authority || $keys[$source] !== Solana::ata($keys[1], $req['asset'], $token) => 'invalid_exact_svm_payload_transaction_signers',
            !sodium_crypto_sign_verify_detached($tx['signatures'][1], $tx['message'], Solana::decode($keys[1])) => 'invalid_exact_svm_payload_signature',
            default => null,
        };
    }

    public function payer(array $payment): ?string
    {
        $wire = is_string($payment['payload']['transaction'] ?? null) ? base64_decode($payment['payload']['transaction'], true) : false;
        $tx = is_string($wire) ? Solana::parse($wire) : null;

        return $tx['keys'][1] ?? null;
    }

    private function message(array $req, string $buyer): string
    {
        $token = Networks::find($req['network']);
        // ponytail: the tokens in Networks only; another mint's decimals come from getTokenSupply if a seller asks for one.
        if (($token['asset'] ?? null) !== $req['asset']) {
            throw new \InvalidArgumentException("Unknown token {$req['asset']} on {$req['network']}.");
        }
        $blockhash = $req['extra']['recentBlockhash'] ?? null;
        if (!is_string($blockhash)) {
            $rpc = $this->rpc ?? throw new \LogicException('No recentBlockhash in the offer and no node to ask.');
            $blockhash = $rpc('getLatestBlockhash', [['commitment' => 'confirmed']])['value']['blockhash'] ?? throw new \RuntimeException('getLatestBlockhash: no blockhash');
        }
        $memo = $req['extra']['memo'] ?? bin2hex(random_bytes(16));

        return Solana::message($req, $buyer, $blockhash, $token['decimals'], $memo);
    }

    /**
     * An unsigned little-endian u64 at $offset, as a decimal string (it may exceed PHP_INT_MAX).
     */
    private static function u64(string $data, int $offset): string
    {
        return gmp_strval(gmp_import(strrev(substr($data, $offset, 8))));
    }
}
