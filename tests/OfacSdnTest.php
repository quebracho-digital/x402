<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quebracho\X402\AddressCheck;
use Quebracho\X402\AddressRejected;
use Quebracho\X402\Budget;
use Quebracho\X402\EvmExact;
use Quebracho\X402\Networks;
use Quebracho\X402\OfacSdn;
use Quebracho\X402\SyncResult;

final class OfacSdnTest extends TestCase
{
    /** Listed in the SDN list shipped in resources/ofac-sdn.json (on it since 2026-11-06). */
    private const LISTED = '0x1CAb8177ACe78b1B6B1c393371F4f2dCAE40CbEB';
    private const CLEAN = '0x000000000000000000000000000000000000dEaD';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ofac-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /** An SDN.CSV row: the remarks are the 12th column. */
    private static function row(int $id, string $remarks): string
    {
        return "$id,\"NAME $id\",\"individual\",\"CYBER2\",-0- ,-0- ,-0- ,-0- ,-0- ,-0- ,-0- ,\"$remarks\"\n";
    }

    /** A CSV with $n ETH addresses, enough to pass the sanity floor. */
    private static function csv(int $n, string $extra = ''): string
    {
        $rows = '';
        for ($i = 1; $i <= $n; ++$i) {
            $rows .= self::row($i, \sprintf('Digital Currency Address - ETH 0x%040x;', $i));
        }

        return $rows.$extra;
    }

    private function snapshot(string $published, array $addresses): string
    {
        $file = $this->dir.'/'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($file, json_encode(['published' => $published, 'addresses' => $addresses]));

        return $file;
    }

    /** @param list<ResponseInterface> $answers */
    private static function http(array $answers, array &$asked = []): ClientInterface
    {
        return new class($answers, $asked) implements ClientInterface {
            public function __construct(private array $answers, private array &$asked)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->asked[] = (string) $request->getUri();

                return array_shift($this->answers) ?? new Response(500);
            }
        };
    }

    public function testParseKeepsEveryCurrencyAndARemarkSpanningLines(): void
    {
        $csv = self::row(1, 'DOB 1980; Digital Currency Address - XBT 1AbcDef; Digital Currency Address - ETH 0xAbC0000000000000000000000000000000000001.')
            .self::row(2, "Email x@y.z; Digital Currency Address - TRX\nTXyz123; alt. Digital Currency Address - ETH 0xAbC0000000000000000000000000000000000001;")
            .self::row(3, 'a.k.a. NOTHING; Digital Currency Address - ');

        self::assertSame(['ETH' => ['0xAbC0000000000000000000000000000000000001'], 'TRX' => ['TXyz123'], 'XBT' => ['1AbcDef']], OfacSdn::parse($csv));
    }

    public function testTheShippedListRejectsAListedAddressInAnyCaseOnAnyEvmNetwork(): void
    {
        $list = OfacSdn::bundled();
        self::assertTrue($list->listed(self::LISTED));
        self::assertTrue($list->listed(strtolower(self::LISTED)));
        self::assertFalse($list->listed(self::CLEAN));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT/', $list->published());
    }

    public function testItIsOnWithoutADispatcherForTheSellerAndTheBuyer(): void
    {
        $req = Networks::requirements('eip155:8453', '0.01', self::LISTED);
        self::assertSame(OfacSdn::REASON, AddressCheck::run(null, AddressCheck::PAYER, strtolower(self::LISTED), $req));
        self::assertNull(AddressCheck::run(null, AddressCheck::PAYER, self::CLEAN, $req));
        // The buyer never signs for a listed payTo, whatever its budget.
        self::assertSame([null, OfacSdn::REASON], Budget::usd('eip155:8453', '1')->choose([new EvmExact()], $req, null));
    }

    public function testAListedAddressNeverReachesTheOtherListeners(): void
    {
        $events = new class implements EventDispatcherInterface {
            public int $calls = 0;
            /** @var list<object> */
            public array $reported = [];

            public function dispatch(object $event): object
            {
                if ($event instanceof AddressCheck) {
                    ++$this->calls;
                } else {
                    $this->reported[] = $event;
                }

                return $event;
            }
        };
        $req = Networks::requirements('eip155:8453', '0.01', self::CLEAN);
        self::assertSame(OfacSdn::REASON, AddressCheck::run($events, AddressCheck::PAYER, self::LISTED, $req));
        self::assertSame(0, $events->calls);
        // The rejection is reported once, to the same dispatcher.
        self::assertCount(1, $events->reported);
        self::assertInstanceOf(AddressRejected::class, $events->reported[0]);
        self::assertSame([AddressCheck::PAYER, self::LISTED, OfacSdn::REASON, null], [$events->reported[0]->role, $events->reported[0]->address, $events->reported[0]->reason, $events->reported[0]->error]);
        self::assertNull(AddressCheck::run($events, AddressCheck::PAYER, self::CLEAN, $req));
        self::assertSame(1, $events->calls, 'other lists subscribe to AddressCheck');
    }

    public function testTheNewerSnapshotWins(): void
    {
        $mine = '0x00000000000000000000000000000000000000aa';
        $newer = new OfacSdn($this->snapshot('2999-01-01T00:00:00Z', ['ETH' => [$mine]]));
        self::assertTrue($newer->listed($mine));
        self::assertFalse($newer->listed(self::LISTED), 'a newer list replaces the shipped one');

        $older = new OfacSdn($this->snapshot('2000-01-01T00:00:00Z', ['ETH' => [$mine]]));
        self::assertFalse($older->listed($mine));
        self::assertTrue($older->listed(self::LISTED), 'an older file than the release is ignored');

        self::assertTrue((new OfacSdn($this->dir.'/never-synced.json'))->listed(self::LISTED));
    }

    public function testAnUnreadableSnapshotRejectsEveryone(): void
    {
        $file = $this->dir.'/bad.json';
        file_put_contents($file, '{"published": 1}');
        $req = Networks::requirements('eip155:8453', '0.01', self::CLEAN);

        self::assertSame('address_check_failed', AddressCheck::run(null, AddressCheck::PAYER, self::CLEAN, $req, new OfacSdn($file)));
    }

    public function testSyncFollowsOfacsRedirectAndWritesASnapshotTheListReads(): void
    // The dates below are far in the future on purpose: OfacSdn uses the younger of the site's snapshot and the
    // list shipped in resources/, so a test snapshot must outlive every refresh of the shipped one.
    {
        $asked = [];
        $http = self::http([
            new Response(302, ['Location' => 'https://bucket.example/SDN.CSV?sig=1']),
            new Response(200, ['Last-Modified' => 'Fri, 06 Nov 2099 19:33:18 GMT'], self::csv(120)),
        ], $asked);
        $file = $this->dir.'/sdn.json';

        self::assertSame(120, OfacSdn::sync($http, new Psr17Factory(), $file)->count);
        self::assertSame([OfacSdn::URL, 'https://bucket.example/SDN.CSV?sig=1'], $asked);
        $list = new OfacSdn($file);
        self::assertSame('2099-11-06T19:33:18Z', $list->published());
        self::assertTrue($list->listed(\sprintf('0x%040x', 7)));
    }

    public function testTheShippedListSyncsAreListSyncs(): void
    {
        $file = $this->dir.'/sdn.json';
        $sync = new \Quebracho\X402\OfacSdnSync(self::http([new Response(200, [], self::csv(150))]), new Psr17Factory());
        self::assertInstanceOf(\Quebracho\X402\ListSync::class, $sync);
        self::assertSame(150, $sync->sync($file)->count);

        $list = $this->dir.'/list.txt';
        $other = new \Quebracho\X402\AddressListSync(self::http([new Response(200, [], "a.near\nb.near\n")]), new Psr17Factory(), 'https://lists.example/x.txt', min: 2);
        self::assertSame(2, $other->sync($list)->count);
        self::assertTrue((new \Quebracho\X402\AddressList([$list]))->listed('b.near'));
    }

    public function testAnOlderOfacListNeverReplacesAYoungerOne(): void
    {
        $file = $this->dir.'/sdn.json';
        self::assertEquals(new SyncResult(120, '2099-11-06T19:33:18Z', true), OfacSdn::write(self::csv(120), 'Fri, 06 Nov 2099 19:33:18 GMT', $file));
        self::assertEquals(new SyncResult(120, '2099-11-06T19:33:18Z', false), OfacSdn::write(self::csv(130), 'Thu, 05 Nov 2099 19:33:18 GMT', $file), 'the younger file is kept');
        self::assertSame('2099-11-06T19:33:18Z', (new OfacSdn($file))->published());
        self::assertSame(140, OfacSdn::write(self::csv(140), 'Sat, 07 Nov 2099 19:33:18 GMT', $file)->count);
        self::assertSame('2099-11-07T19:33:18Z', (new OfacSdn($file))->published());
    }

    public function testSyncKeepsTheOldFileWhenTheAnswerIsNotTheList(): void
    {
        $file = $this->dir.'/sdn.json';
        file_put_contents($file, 'old');
        $cases = [
            'too few addresses' => [new Response(200, [], self::csv(3))],
            'an http redirect' => [new Response(302, ['Location' => 'http://bucket.example/SDN.CSV'])],
            'an error' => [new Response(503)],
        ];
        foreach ($cases as $case => $answers) {
            try {
                OfacSdn::sync(self::http($answers), new Psr17Factory(), $file);
                self::fail("$case: no exception");
            } catch (\RuntimeException) {
                self::assertSame('old', file_get_contents($file), $case);
            }
        }
    }
}
