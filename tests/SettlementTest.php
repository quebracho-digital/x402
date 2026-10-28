<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\Settlement;

final class SettlementTest extends TestCase
{
    private static function payment(string $from = '0xAbC', string $nonce = '0x01', string $network = 'eip155:84532'): array
    {
        return ['accepted' => ['network' => $network], 'payload' => ['signature' => '0xsig', 'authorization' => ['from' => $from, 'nonce' => $nonce]]];
    }

    public function testTheKeyIsWhatTheChainSpendsOnce(): void
    {
        $key = Settlement::key(self::payment());
        self::assertSame($key, Settlement::key(self::payment('0xabc')), 'payer case does not buy a second delivery');
        $resigned = self::payment();
        $resigned['payload']['signature'] = '0xother';
        self::assertSame($key, Settlement::key($resigned), 're-signing the same nonce does not either');
        self::assertNotSame($key, Settlement::key(self::payment(nonce: '0x02')));
        self::assertNotSame($key, Settlement::key(self::payment(from: '0xdef')));
        self::assertNotSame($key, Settlement::key(self::payment(network: 'eip155:5042002')));
    }

    public function testOnlyALiteralTrueIsASettlement(): void
    {
        $req = ['network' => 'eip155:84532'];
        $ok = ['success' => true, 'transaction' => '0xtx', 'network' => 'eip155:84532', 'payer' => '0xabc'];
        self::assertSame($ok, Settlement::run(fn () => $ok, self::payment(), $req));
        foreach ([['success' => 'true'], ['success' => 1], null, 'ok'] as $answer) {
            self::assertFalse(Settlement::run(fn () => $answer, self::payment(), $req)['success']);
        }
        self::assertSame('insufficient_funds', Settlement::run(fn () => ['success' => false, 'errorReason' => 'insufficient_funds'], self::payment(), $req)['errorReason']);
        self::assertFalse(Settlement::run(fn () => throw new \RuntimeException(), self::payment(), $req)['success']);
    }
}
