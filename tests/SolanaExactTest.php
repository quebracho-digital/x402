<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\Budget;
use Quebracho\X402\Networks;
use Quebracho\X402\Settlement;
use Quebracho\X402\Solana;
use Quebracho\X402\SolanaExact;
use Quebracho\X402\SolanaKey;

final class SolanaExactTest extends TestCase
{
    private const NETWORK = 'solana:EtWTRABZaYq6iMfeYKouRu166VU2xqa1';
    private const USDC = 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v';
    private const FEE_PAYER = 'CKPKJWNdJEqa81x7CkZ14BVPiY6y16Sxs7owznqtWYp5';

    private SolanaKey $buyer;

    protected function setUp(): void
    {
        $this->buyer = new SolanaKey(Solana::encode(str_repeat("\1", 32)));
    }

    private function req(): array
    {
        $req = Networks::requirements(self::NETWORK, '0.01', 'DcQNaByvstRZCERvb9UJHgHiQe8YX2FMdPptM86psQyz');
        $req['extra'] = ['feePayer' => self::FEE_PAYER, 'recentBlockhash' => 'EZ3rST5dvHmbanh75jc4PuLfV96vp9fEYBVeNk4FfM1k'];

        return $req;
    }

    private function pay(array $req): array
    {
        return (new SolanaExact($this->buyer))->pay(['resource' => ['url' => 'mcp://tool/wisdom']], $req);
    }

    /**
     * The payment with its message rewritten by $edit and re-signed by the buyer.
     */
    private function forged(array $req, \Closure $edit): array
    {
        $payment = $this->pay($req);
        $tx = Solana::parse(base64_decode($payment['payload']['transaction']));
        $message = $edit($tx['message']);
        $payment['payload']['transaction'] = Solana::transaction($message, $this->buyer);

        return $payment;
    }

    public function testAssociatedTokenAccountsMatchSolana(): void
    {
        $this->assertSame('7eoSSCvNpRi9r1tuta95n3ycUthyCZ5dYRjFqosmWT13', Solana::ata('DcQNaByvstRZCERvb9UJHgHiQe8YX2FMdPptM86psQyz', self::USDC));
        $this->assertSame('EqU5zLz9apQpMvgkgK7oANDtCGtn83vBgMwKmrWaUNGL', Solana::ata('BqRWCWor1ZqdnKPzuQXB6TdqDp1bzpkEx5UDasMM2KSu', self::USDC));
    }

    public function testKeyFormats(): void
    {
        $secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat("\1", 32)));
        $this->assertSame($this->buyer->address(), (new SolanaKey(json_encode(array_map('ord', str_split($secret)))))->address());
        $this->assertSame($this->buyer->address(), (new SolanaKey(Solana::encode($secret)))->address());
    }

    public function testAPaymentFromThisLibraryVerifies(): void
    {
        $req = $this->req();
        $payment = $this->pay($req);
        $seller = new SolanaExact();
        $this->assertNull($seller->verify($payment, $req));
        $this->assertSame($this->buyer->address(), $seller->payer($payment));
        // The fee payer's slot is empty: the facilitator signs it.
        $this->assertSame(str_repeat("\0", 64), Solana::parse(base64_decode($payment['payload']['transaction']))['signatures'][0]);
    }

    public function testTamperedPaymentsAreRefused(): void
    {
        $req = $this->req();
        $seller = new SolanaExact();
        $other = 'BqRWCWor1ZqdnKPzuQXB6TdqDp1bzpkEx5UDasMM2KSu';
        $cases = [
            'another seller' => [$req, $this->pay(['payTo' => $other] + $req)],
            'less money' => [$req, $this->pay(['amount' => '9999'] + $req)],
            'another fee payer' => [$req, $this->pay(['extra' => ['feePayer' => $other] + $req['extra']] + $req)],
            'other memo' => [['extra' => ['memo' => 'invoice-1'] + $req['extra']] + $req, $this->pay(['extra' => ['memo' => 'invoice-2'] + $req['extra']] + $req)],
            'bad signature' => [$req, (function () use ($req) {
                $p = $this->pay($req);
                $wire = base64_decode($p['payload']['transaction']);
                $wire[70] = chr(ord($wire[70]) ^ 1);
                $p['payload']['transaction'] = base64_encode($wire);

                return $p;
            })()],
            // The fee payer (key 0) as the transfer's authority: a drain.
            'fee payer in an instruction' => [$req, $this->forged($req, fn (string $m) => self::replaceTransferAccounts($m, [2, 4, 3, 0]))],
            'garbage' => [$req, ['x402Version' => 2, 'accepted' => $req, 'payload' => ['transaction' => base64_encode('nope')]]],
            // The seller asked for a memo the transaction does not carry.
            'memo missing' => [['extra' => ['memo' => 'invoice-1'] + $req['extra']] + $req, $this->forged($req, fn (string $m) => self::dropLastInstruction($m))],
            // The destination moved to the read-only section: it would fail on chain.
            'read-only destination' => [$req, $this->forged($req, fn (string $m) => $m[3] === "\x04" ? substr_replace($m, "\x06", 3, 1) : $m)],
        ];
        $expected = ['garbage' => 'invalid_payload'];
        foreach (['another seller' => 'recipient_mismatch', 'less money' => 'amount_insufficient', 'another fee payer' => 'transaction_signers', 'other memo' => 'memo_mismatch', 'bad signature' => 'signature', 'fee payer in an instruction' => 'transaction_fee_payer_included_in_instruction_accounts', 'memo missing' => 'memo_mismatch', 'read-only destination' => 'transaction_instructions'] as $case => $reason) {
            $expected[$case] = 'invalid_exact_svm_payload_'.$reason;
        }
        foreach ($cases as $case => [$wanted, $payment]) {
            $payment['accepted'] = $wanted;
            $this->assertSame($expected[$case], $seller->verify($payment, $wanted), $case);
        }
    }

    public function testOnePaymentOneClaimWhateverTheFeePayerSlotHolds(): void
    {
        $payment = $this->pay($this->req());
        $wire = base64_decode($payment['payload']['transaction']);
        $other = $payment;
        $other['payload']['transaction'] = base64_encode(substr($wire, 0, 1).str_repeat("\x7f", 64).substr($wire, 65));
        $this->assertSame(Settlement::key($payment), Settlement::key($other));
    }

    public function testTheBuyersBudgetSignsKnownSolanaOffers(): void
    {
        $budget = Budget::usd(self::NETWORK, '1');
        $req = $this->req();
        $this->assertTrue($budget->allows($req));
        $this->assertFalse($budget->allows(['asset' => 'So11111111111111111111111111111111111111112'] + $req));
        $this->assertFalse($budget->allows(['extra' => ['feePayer' => '0xnope']] + $req));
    }

    /** Drops the memo, the last instruction of a message we built (its count is a 1-byte shortvec). */
    private static function dropLastInstruction(string $message): string
    {
        $memo = strrpos($message, "\x07\x00\x20");
        $count = strrpos(substr($message, 0, $memo), "\x04\x06\x00\x05");

        return substr_replace(substr($message, 0, $memo)."\x00", "\x03", $count, 1);
    }

    /** Rewrites the TransferChecked instruction's account indexes in a v0 message we built. */
    private static function replaceTransferAccounts(string $message, array $accounts): string
    {
        $at = strpos($message, "\x05\x04\x02\x04\x03\x01");

        return substr_replace($message, "\x05\x04".implode('', array_map('chr', $accounts)), $at, 6);
    }
}
