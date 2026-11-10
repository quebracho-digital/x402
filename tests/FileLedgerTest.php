<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use PHPUnit\Framework\TestCase;
use Quebracho\X402\FileLedger;
use Quebracho\X402\LedgerUnavailableException;

final class FileLedgerTest extends TestCase
{
    use TempClaims;

    private function ledger(): FileLedger
    {
        return new FileLedger($this->claimsDir());
    }

    public function testOnlyTheFirstClaimWins(): void
    {
        $ledger = $this->ledger();
        self::assertTrue($ledger->claim('k', 600));
        self::assertFalse($ledger->claim('k', 600));
        self::assertSame(['status' => 'claimed'], $ledger->get('k'));
    }

    public function testAnExpiredClaimCanBeTakenAgain(): void
    {
        $ledger = $this->ledger();
        // A negative TTL is already expired: no sleep needed.
        self::assertTrue($ledger->claim('k', -1));
        self::assertNull($ledger->get('k'));
        self::assertTrue($ledger->claim('k', 600));
    }

    public function testPutReplacesTheStateAndReleaseDeletesIt(): void
    {
        $ledger = $this->ledger();
        $ledger->claim('k', 600);
        $ledger->put('k', ['status' => 'settled', 'transaction' => '0xabc'], 600);
        self::assertSame(['status' => 'settled', 'transaction' => '0xabc'], $ledger->get('k'));

        $ledger->release('k');
        self::assertNull($ledger->get('k'));
        self::assertTrue($ledger->claim('k', 600));
    }

    public function testAnExpiredPutIsNotThere(): void
    {
        $ledger = $this->ledger();
        $ledger->put('k', ['status' => 'delivered'], -1);
        self::assertNull($ledger->get('k'));
        self::assertNull($this->ledger()->get('never-written'));
    }

    /** A path whose directory cannot exist: a regular file stands where the ledger's directory should be. */
    private function unusableLedger(): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'x402-file-');

        return [new FileLedger($file.'/ledger'), $file];
    }

    public function testAnUnusableDirectoryMakesAClaimLedgerUnavailable(): void
    {
        [$ledger, $file] = $this->unusableLedger();
        try {
            $this->expectException(LedgerUnavailableException::class);
            $ledger->claim('k', 600);
        } finally {
            unlink($file);
        }
    }

    public function testAnUnusableDirectoryMakesAPutLedgerUnavailable(): void
    {
        [$ledger, $file] = $this->unusableLedger();
        try {
            $this->expectException(LedgerUnavailableException::class);
            $ledger->put('k', ['status' => 'settled'], 600);
        } finally {
            unlink($file);
        }
    }

    public function testAnUnusableDirectoryMakesAnOpenLedgerUnavailable(): void
    {
        [$ledger, $file] = $this->unusableLedger();
        try {
            $this->expectException(LedgerUnavailableException::class);
            $ledger->open('k', [], 600);
        } finally {
            unlink($file);
        }
    }

    /**
     * A directory that can be read but not written: its files cannot be created, replaced or deleted.
     *
     * @param \Closure(FileLedger): mixed $operation
     */
    private function readOnly(\Closure $operation): void
    {
        $dir = $this->claimsDir();
        $ledger = new FileLedger($dir);
        $ledger->put('k', ['status' => 'settled'], 600);
        $ledger->open('k', [], 600);
        $ledger->put('dead', ['status' => 'settled'], -1);
        $ledger->claim('lock', 600); // leaves .lock, so a claim gets past the lock
        if (\function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            self::markTestSkipped('root writes to a read-only directory: run the tests as another user.');
        }
        chmod($dir, 0500);
        try {
            $this->expectException(LedgerUnavailableException::class);
            $operation($ledger);
        } finally {
            chmod($dir, 0700);
        }
    }

    public function testAReadOnlyDirectoryMakesAClaimLedgerUnavailable(): void
    {
        $this->readOnly(static fn (FileLedger $l) => $l->claim('new', 600));
    }

    public function testAReadOnlyDirectoryMakesAPutLedgerUnavailable(): void
    {
        $this->readOnly(static fn (FileLedger $l) => $l->put('k', ['status' => 'delivered'], 600));
    }

    public function testAReadOnlyDirectoryMakesAnOpenLedgerUnavailable(): void
    {
        $this->readOnly(static fn (FileLedger $l) => $l->open('k', ['status' => 'pending'], 600));
    }

    public function testAReleaseThatCannotDeleteIsLedgerUnavailable(): void
    {
        $this->readOnly(static fn (FileLedger $l) => $l->release('k'));
    }

    public function testACloseThatCannotDeleteIsLedgerUnavailable(): void
    {
        $this->readOnly(static fn (FileLedger $l) => $l->close('k'));
    }

    public function testAPruneThatCannotDeleteIsLedgerUnavailable(): void
    {
        $this->readOnly(static fn (FileLedger $l) => $l->prune());
    }

    public function testReleasingAnAbsentKeyIsNotAnError(): void
    {
        $ledger = $this->ledger();
        $ledger->release('never-claimed');
        $ledger->close('never-opened');
        self::assertTrue($ledger->claim('never-claimed', 600));
    }

    public function testOpenListsTheKeyWithItsRefUntilClosed(): void
    {
        $ledger = $this->ledger();
        $ledger->open('k', ['status' => 'settling', 'transaction' => '0xabc'], 600);
        $open = $ledger->openKeys(10);
        self::assertSame(['k'], array_keys($open));
        self::assertSame(['status' => 'settling', 'transaction' => '0xabc'], array_diff_key($open['k'], ['opened' => 0]));
        self::assertEqualsWithDelta(time(), $open['k']['opened'], 2);

        $ledger->close('k');
        self::assertSame([], $ledger->openKeys(10));
    }

    public function testOpeningAgainKeepsTheFirstOpenedTime(): void
    {
        $ledger = $this->ledger();
        $ledger->open('k', ['status' => 'settling', 'opened' => 1], 600);
        $ledger->open('k', ['status' => 'pending'], 600);
        self::assertSame(['status' => 'pending', 'opened' => 1], $ledger->openKeys(10)['k']);
    }

    public function testOpenKeysHonoursTheLimitAndSkipsTheExpired(): void
    {
        $ledger = $this->ledger();
        $ledger->open('a', [], 600);
        $ledger->open('b', [], 600);
        $ledger->open('dead', [], -1);
        self::assertCount(1, $ledger->openKeys(1));
        self::assertEqualsCanonicalizing(['a', 'b'], array_keys($ledger->openKeys(10)));
    }

    public function testReleaseClosesTheEntryAndPruneLeavesOpenEntriesAlone(): void
    {
        $ledger = $this->ledger();
        $ledger->put('k', ['status' => 'pending'], 600);
        $ledger->open('k', [], 600);
        $ledger->put('dead', ['status' => 'settled'], -1);
        self::assertSame(1, $ledger->prune());
        self::assertSame(['k'], array_keys($ledger->openKeys(10)));

        $ledger->release('k');
        self::assertSame([], $ledger->openKeys(10));
        self::assertNull($ledger->get('k'));
    }

    public function testPruneRemovesOnlyTheExpired(): void
    {
        $ledger = $this->ledger();
        $ledger->put('live', ['status' => 'settled'], 600);
        $ledger->put('dead', ['status' => 'settled'], -1);
        self::assertSame(1, $ledger->prune());
        self::assertSame(['status' => 'settled'], $ledger->get('live'));
    }
}
