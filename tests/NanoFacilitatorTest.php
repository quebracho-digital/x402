<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quebracho\X402\FacilitatorException;
use Quebracho\X402\NanoExact;
use Quebracho\X402\NanoFacilitator;

/**
 * Against a node that answers block_info as a Nano node does (strings everywhere, `confirmed` "true").
 */
final class NanoFacilitatorTest extends TestCase
{
    private const int NOW = 1_800_000_000;
    private const string NODE = 'https://node.example/rpc';

    /** @var list<array<string, mixed>> the bodies the node received */
    private array $calls = [];

    private static function block(array $change = [], array $contents = []): array
    {
        $payment = NanoExactTest::payment();

        return $change + [
            'block_account' => NanoExactTest::buyer(),
            'amount' => NanoExactTest::req()['amount'],
            'balance' => '5000000000000000000000000000',
            'height' => '12',
            'local_timestamp' => (string) (self::NOW - 5),
            'successor' => '0000000000000000000000000000000000000000000000000000000000000000',
            'confirmed' => 'true',
            'contents' => $contents + [
                'type' => 'state',
                'account' => NanoExactTest::buyer(),
                'previous' => str_repeat('C', 64),
                'representative' => NanoExact::representative($payment['payload']['orderId'], NanoExactTest::req()['payTo'], NanoExactTest::req()['amount']),
                'balance' => '5000000000000000000000000000',
                'link' => strtoupper(bin2hex((string) NanoExact::publicKey(NanoExactTest::req()['payTo']))),
                'link_as_account' => NanoExactTest::req()['payTo'],
                'signature' => str_repeat('D', 128),
                'work' => '0000000000000000',
            ],
            'subtype' => 'send',
        ];
    }

    private function facilitator(string $body, int $status = 200, bool $down = false): NanoFacilitator
    {
        $client = new class($body, $status, $down, $this->calls) implements ClientInterface {
            /** @param list<array<string, mixed>> $calls */
            public function __construct(private string $body, private int $status, private bool $down, private array &$calls)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls[] = ['url' => (string) $request->getUri()] + json_decode((string) $request->getBody(), true);
                if ($this->down) {
                    throw new class('connection refused') extends \RuntimeException implements NetworkExceptionInterface {
                        public function getRequest(): RequestInterface
                        {
                            throw new \LogicException('not needed');
                        }
                    };
                }

                return new Response($this->status, [], $this->body);
            }
        };
        $f = new Psr17Factory();

        return new NanoFacilitator($client, $f, $f, self::NODE, static fn (): int => self::NOW);
    }

    private function verdict(array $block): array
    {
        return $this->facilitator((string) json_encode($block))->verify(NanoExactTest::payment(), NanoExactTest::req());
    }

    public function testAConfirmedSendOfTheOfferIsValidAndSettlesAsItsBlock(): void
    {
        $facilitator = $this->facilitator((string) json_encode(self::block()));
        $payment = NanoExactTest::payment();

        $this->assertSame(['isValid' => true, 'payer' => NanoExactTest::buyer()], $facilitator->verify($payment, NanoExactTest::req()));
        $this->assertSame(
            ['success' => true, 'transaction' => $payment['payload']['block'], 'network' => NanoExact::NETWORK, 'payer' => NanoExactTest::buyer()],
            $facilitator->settle($payment, NanoExactTest::req()),
        );
        $this->assertSame(['url' => self::NODE, 'action' => 'block_info', 'json_block' => 'true', 'hash' => $payment['payload']['block']], $this->calls[0]);
    }

    public function testEachWayTheBlockMissesTheOfferIsRefused(): void
    {
        $other = NanoExact::account(str_repeat("\x22", 32));
        $cases = [
            NanoFacilitator::REASON_UNCONFIRMED => self::block(['confirmed' => 'false']),
            NanoFacilitator::REASON_NOT_A_SEND => self::block(['subtype' => 'receive']),
            NanoFacilitator::REASON_PAYER => self::block(['block_account' => $other]),
            NanoFacilitator::REASON_RECIPIENT => self::block([], ['link_as_account' => $other]),
            // Exact: more is not the offer, less is not either.
            NanoFacilitator::REASON_AMOUNT => self::block(['amount' => '1000000000000000000000000001']),
            // Another order's commitment: a public block hash presented by someone who does not hold its order id.
            NanoFacilitator::REASON_BINDING => self::block([], ['representative' => NanoExact::representative(str_repeat('ab', 32), NanoExactTest::req()['payTo'], NanoExactTest::req()['amount'])]),
            NanoFacilitator::REASON_TIME => self::block(['local_timestamp' => (string) (self::NOW - NanoExact::MAX_TIMEOUT - 1)]),
            NanoFacilitator::REASON_NOT_FOUND => ['error' => 'Block not found'],
            NanoFacilitator::REASON_ANSWER => ['confirmed' => 'true'],
        ];
        foreach ($cases as $reason => $block) {
            $this->assertSame(['isValid' => false, 'payer' => null, 'invalidReason' => $reason], $this->verdict($block), $reason);
        }
    }

    public function testABlockTheNodeCannotDateIsRefused(): void
    {
        foreach (['0', '', '1.8e9', (string) (self::NOW + NanoExact::SKEW + 1)] as $time) {
            $this->assertSame(NanoFacilitator::REASON_TIME, $this->verdict(self::block(['local_timestamp' => $time]))['invalidReason'] ?? null, "time '$time'");
        }
        $this->assertSame(true, $this->verdict(self::block(['local_timestamp' => (string) (self::NOW - NanoExact::MAX_TIMEOUT)]))['isValid']);
    }

    public function testASettleThatFailsSaysWhy(): void
    {
        $settled = $this->facilitator((string) json_encode(self::block(['confirmed' => 'false'])))->settle(NanoExactTest::payment(), NanoExactTest::req());

        $this->assertSame(false, $settled['success']);
        $this->assertSame('', $settled['transaction']);
        $this->assertSame(NanoFacilitator::REASON_UNCONFIRMED, $settled['errorReason']);
    }

    public function testAPaymentRefusedOfflineCostsNoNodeCall(): void
    {
        $payment = NanoExactTest::payment();
        $payment['payload']['block'] = strtolower($payment['payload']['block']);

        $this->assertSame('invalid_payload', $this->facilitator('{}')->verify($payment, NanoExactTest::req())['invalidReason'] ?? null);
        $this->assertSame([], $this->calls);
    }

    public function testANodeThatIsDownThrows(): void
    {
        $this->expectException(FacilitatorException::class);
        $this->facilitator('', 200, true)->verify(NanoExactTest::payment(), NanoExactTest::req());
    }

    public function testANodeThatAnswersNoJsonThrows(): void
    {
        $this->expectException(FacilitatorException::class);
        $this->facilitator('<html>bad gateway</html>', 502)->verify(NanoExactTest::payment(), NanoExactTest::req());
    }

    public function testANodeIsAnHttpUrl(): void
    {
        $f = new Psr17Factory();
        $client = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return new Response(200, [], '{}');
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        new NanoFacilitator($client, $f, $f, 'node.example:7076');
    }
}
