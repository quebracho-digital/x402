<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Quebracho\X402\Concordium;
use Quebracho\X402\InvalidPaymentException;

final class ConcordiumTest extends TestCase
{
    private const int NOW = 1700000000;
    private const string SENDER = '35G83kEaPTNk9DkdkbXtGyRiBvZpxAbqgYSzdLMfJLkKwHU7TE';
    private const string PAY_TO = '3Cn4s3SJMEXMTroMva4pEM5WGER1CVKtj1nk6ypKwg7AJV6JqA';
    private const string SPONSOR = '3LJ1gLe2K1fxnVr66YbkBijJLYGBSp3wmV8VadGzb1TzcXNtAV';
    private const string SEED = '0101010101010101010101010101010101010101010101010101010101010101';

    private function tx(): array
    {
        return [
            'version' => 1,
            'header' => [
                'sender' => self::SENDER,
                'nonce' => 42,
                'expiry' => self::NOW + 60,
                'executionEnergyAmount' => Concordium::TRANSFER_ENERGY,
                'numSignatures' => 1,
                'sponsor' => ['address' => self::SPONSOR, 'numSignatures' => 1],
            ],
            'payload' => ['type' => 'transfer', 'toAddress' => self::PAY_TO, 'amount' => '1000000'],
            'signatures' => ['sender' => [], 'sponsor' => []],
        ];
    }

    public function testPartsExposeWhatTheDigestCommitsTo(): void
    {
        $parts = Concordium::parts($this->tx());
        $this->assertSame(Concordium::decodeAddress(self::SENDER), $parts['sender']);
        $this->assertSame(Concordium::decodeAddress(self::PAY_TO), $parts['to']);
        $this->assertSame(Concordium::decodeAddress(self::SPONSOR), $parts['sponsor']);
        $this->assertSame(42, $parts['nonce']);
        $this->assertSame(self::NOW + 60, $parts['expiry']);
        $this->assertSame(1_000_000, $parts['amount']);
        $this->assertSame(635, $parts['energy'], '300 base + 2 signatures + 135 bytes');
        $this->assertSame(635, Concordium::totalEnergy($this->tx()));
        $this->assertSame(32, strlen(Concordium::signHash($this->tx())));
    }

    public function testTheSponsorCanBeWrittenWithEitherOneSpelling(): void
    {
        $account = $this->tx();
        unset($account['header']['sponsor']['address']);
        $account['header']['sponsor']['account'] = self::SPONSOR;

        $this->assertSame(Concordium::parts($this->tx())['sponsor'], Concordium::parts($account)['sponsor']);
        $this->assertSame(Concordium::signHash($this->tx()), Concordium::signHash($account));
    }

    #[DataProvider('badTransaction')]
    public function testPartsRefuseATransactionTheDigestCannotRead(callable $break): void
    {
        $tx = $this->tx();
        $break($tx);
        $this->expectException(InvalidPaymentException::class);
        Concordium::parts($tx);
    }

    public static function badTransaction(): iterable
    {
        yield 'extra transaction field' => [function (array &$tx) { $tx['extra'] = 1; }];
        yield 'payload is not a transfer' => [function (array &$tx) { $tx['payload']['type'] = 'tokenUpdate'; }];
        yield 'extra header field' => [function (array &$tx) { $tx['header']['extra'] = 1; }];
        yield 'extra payload field' => [function (array &$tx) { $tx['payload']['extra'] = 1; }];
        yield 'sponsor in both spellings' => [function (array &$tx) { $tx['header']['sponsor']['account'] = self::SPONSOR; }];
        yield 'sponsor in no spelling' => [function (array &$tx) { unset($tx['header']['sponsor']['address']); }];
        yield 'sender is not a string' => [function (array &$tx) { $tx['header']['sender'] = 42; }];
        yield 'recipient is not a string' => [function (array &$tx) { $tx['payload']['toAddress'] = 42; }];
        yield 'sponsor is not a string' => [function (array &$tx) { $tx['header']['sponsor']['address'] = 42; }];
        yield 'sender is not an address' => [function (array &$tx) { $tx['header']['sender'] = 'nope'; }];
        yield 'recipient is not an address' => [function (array &$tx) { $tx['payload']['toAddress'] = 'nope'; }];
        yield 'sponsor is not an address' => [function (array &$tx) { $tx['header']['sponsor']['address'] = 'nope'; }];
        yield 'no sender signatures' => [function (array &$tx) { $tx['header']['numSignatures'] = 0; }];
        yield 'sender signatures as text' => [function (array &$tx) { $tx['header']['numSignatures'] = '1'; }];
        yield 'negative sponsor signatures' => [function (array &$tx) { $tx['header']['sponsor']['numSignatures'] = -1; }];
        yield 'sponsor signatures as text' => [function (array &$tx) { $tx['header']['sponsor']['numSignatures'] = '1'; }];
        yield 'negative base energy' => [function (array &$tx) { $tx['header']['executionEnergyAmount'] = -1; }];
        yield 'base energy as text' => [function (array &$tx) { $tx['header']['executionEnergyAmount'] = '300'; }];
        yield 'negative nonce' => [function (array &$tx) { $tx['header']['nonce'] = -1; }];
        yield 'nonce with a leading zero' => [function (array &$tx) { $tx['header']['nonce'] = '01'; }];
        yield 'nonce as a float' => [function (array &$tx) { $tx['header']['nonce'] = 1.5; }];
        yield 'nonce as an array' => [function (array &$tx) { $tx['header']['nonce'] = []; }];
        yield 'negative expiry' => [function (array &$tx) { $tx['header']['expiry'] = -1; }];
        yield 'expiry with a leading zero' => [function (array &$tx) { $tx['header']['expiry'] = '01'; }];
        yield 'expiry as a float' => [function (array &$tx) { $tx['header']['expiry'] = 1.5; }];
        yield 'negative amount' => [function (array &$tx) { $tx['payload']['amount'] = -1; }];
        yield 'amount with a leading zero' => [function (array &$tx) { $tx['payload']['amount'] = '01'; }];
        yield 'amount as a float' => [function (array &$tx) { $tx['payload']['amount'] = 1.5; }];
        yield 'amount as an array' => [function (array &$tx) { $tx['payload']['amount'] = []; }];
    }

    public function testDecodeAddressRefusesWhatIsNotAnAccountAddress(): void
    {
        foreach (['0OI', 'nope', substr(self::SENDER, 0, -1).'x', '', str_repeat('1', 50), '1'.self::SENDER, '11'.self::SENDER] as $bad) {
            try {
                Concordium::decodeAddress($bad);
                $this->fail("\"$bad\" is no account address");
            } catch (InvalidPaymentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testIsAddressIsFalseInsteadOfThrowing(): void
    {
        $this->assertFalse(Concordium::isAddress(''));
        $this->assertFalse(Concordium::isAddress('0OI'));
        $this->assertFalse(Concordium::isAddress('nope'));
        $this->assertFalse(Concordium::isAddress('1'.self::SENDER));
    }

    public function testSignedByRefusesBadInputWithoutThrowing(): void
    {
        $tx = $this->tx();
        $public = bin2hex(sodium_crypto_sign_publickey_from_secretkey(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin(self::SEED)))));
        $signature = bin2hex(sodium_crypto_sign_detached(Concordium::signHash($tx), sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(hex2bin(self::SEED)))));

        $this->assertTrue(Concordium::signedBy($tx, $signature, $public));
        $this->assertFalse(Concordium::signedBy($tx, 'zz', $public));
        $this->assertFalse(Concordium::signedBy($tx, $signature, 'zz'));
        $this->assertFalse(Concordium::signedBy($tx, str_repeat('a', 128), $public));
        $broken = $tx;
        $broken['payload']['amount'] = '1';
        $this->assertFalse(Concordium::signedBy($broken, $signature, $public));
    }

    public function testEnergyCountsTheBaseSignaturesAndBytes(): void
    {
        $this->assertSame(300, Concordium::energy(300, 0, 0));
        $this->assertSame(635, Concordium::energy(300, 2, 135));
    }
}
