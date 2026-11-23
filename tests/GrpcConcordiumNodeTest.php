<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quebracho\X402\Concordium;
use Quebracho\X402\ConcordiumExact;
use Quebracho\X402\FacilitatorException;
use Quebracho\X402\GrpcConcordiumNode;
use Quebracho\X402\GrpcException;
use Quebracho\X402\GrpcWeb;
use Quebracho\X402\Proto;

final class GrpcConcordiumNodeTest extends TestCase
{
    private const string SENDER = '35G83kEaPTNk9DkdkbXtGyRiBvZpxAbqgYSzdLMfJLkKwHU7TE';
    private const string PAY_TO = '3Cn4s3SJMEXMTroMva4pEM5WGER1CVKtj1nk6ypKwg7AJV6JqA';
    private const string SPONSOR = '3LJ1gLe2K1fxnVr66YbkBijJLYGBSp3wmV8VadGzb1TzcXNtAV';
    private const string SEED = '0101010101010101010101010101010101010101010101010101010101010101';
    private const string HASH = 'abababababababababababababababababababababababababababababababab';

    private RecordingHttpClient $http;
    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->factory = new Psr17Factory();
    }

    private function rpc(): GrpcWeb
    {
        return new GrpcWeb($this->http, $this->factory, $this->factory, 'https://grpc.testnet.concordium.com:20000');
    }

    private function node(): GrpcConcordiumNode
    {
        return new GrpcConcordiumNode($this->rpc());
    }

    private function answer(string $message): void
    {
        $this->http->answer = $message;
    }

    public function testOneCallPostsAFrameAndReadsTheDataMessage(): void
    {
        $this->answer('the answer');
        $this->assertSame('the answer', $this->rpc()->call('concordium.v2.Queries', 'GetAccountInfo', 'the request'));
        $request = $this->http->requests[0];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://grpc.testnet.concordium.com:20000/concordium.v2.Queries/GetAccountInfo', (string) $request->getUri());
        $this->assertSame('application/grpc-web+proto', $request->getHeaderLine('content-type'));
        $this->assertSame("\0".pack('N', 11).'the request', (string) $request->getBody());
    }

    public function testANonZeroStatusThrowsWithItsCode(): void
    {
        $this->http->status = '5';
        try {
            $this->rpc()->call('concordium.v2.Queries', 'GetAccountInfo', '');
            $this->fail('A non-zero gRPC status must throw.');
        } catch (GrpcException $e) {
            $this->assertSame(5, $e->status);
        }
    }

    public function testAccountInfoIsDecodedIntoWhatTheChecksRead(): void
    {
        $public = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(hex2bin(self::SEED)));
        // Map entries may omit a key that is the default (0), as the chain does for credential 0 and key 0.
        $credential = Proto::message(2, Proto::message(1, Proto::message(1, Proto::message(2, Proto::bytes(1, $public))).Proto::message(2, Proto::uint(1, 1))));
        $info = Proto::message(1, Proto::uint(1, 42))
            .Proto::message(2, Proto::uint(1, 10_000_000))
            .Proto::message(4, Proto::message(2, $credential))
            .Proto::message(5, Proto::uint(1, 1))
            .Proto::message(12, Proto::uint(1, 9_000_000));
        $this->answer($info);

        $this->assertSame([
            'amount' => 10_000_000,
            'available' => 9_000_000,
            'threshold' => 1,
            'credentials' => [0 => ['threshold' => 1, 'keys' => [0 => bin2hex($public)]]],
        ], $this->node()->accountInfo(self::SENDER));
    }

    public function testAMissingAccountIsNull(): void
    {
        $this->http->status = '5';
        $this->assertNull($this->node()->accountInfo(self::SENDER));
    }

    public function testTheNextNonceIsRead(): void
    {
        $this->answer(Proto::message(1, Proto::uint(1, 42)));
        $this->assertSame(42, $this->node()->nextNonce(self::SENDER));
    }

    public function testSendEncodesTheTransferAndReturnsItsHash(): void
    {
        $payment = (new ConcordiumExact(['address' => self::SENDER, 'signKey' => self::SEED], fn () => 42, fn () => 1700000000))->pay([], $this->req());
        $tx = $payment['payload']['signedTransaction'];
        $tx['signatures']['sponsor'] = ['0' => ['0' => str_repeat('b', 128)]];
        $this->answer(Proto::bytes(1, hex2bin(self::HASH)));

        $this->assertSame(self::HASH, $this->node()->send($tx));
        $request = $this->http->requests[0];
        $this->assertSame('https://grpc.testnet.concordium.com:20000/concordium.v2.Queries/SendBlockItem', (string) $request->getUri());
        $body = (string) $request->getBody();
        $requestMessage = Proto::parse(substr($body, 5));
        $transaction = Proto::nested($requestMessage, 4);
        $header = Proto::nested($transaction, 2);
        $payload = Proto::nested(Proto::nested($transaction, 3), 5);
        $this->assertSame(Concordium::decodeAddress(self::SENDER), Proto::first(Proto::nested($header, 1), 1));
        $this->assertSame(42, Proto::first(Proto::nested($header, 2), 1));
        $this->assertSame(Concordium::totalEnergy($tx), Proto::first(Proto::nested($header, 3), 1));
        $this->assertSame(1700000060, Proto::first(Proto::nested($header, 5), 1));
        $this->assertSame(Concordium::decodeAddress(self::SPONSOR), Proto::first(Proto::nested($header, 6), 1));
        $this->assertSame(1_000_000, Proto::first(Proto::nested($payload, 1), 1));
        $this->assertSame(Concordium::decodeAddress(self::PAY_TO), Proto::first(Proto::nested($payload, 2), 1));
    }

    public function testSendRefusesANonV1Transaction(): void
    {
        $payment = (new ConcordiumExact(['address' => self::SENDER, 'signKey' => self::SEED], fn () => 42, fn () => 1700000000))->pay([], $this->req());
        $tx = $payment['payload']['signedTransaction'];
        $tx['version'] = 0;

        $this->expectException(FacilitatorException::class);
        $this->node()->send($tx);
    }

    public function testAFinalizedTransferIsReadWithItsAmountAndReceiver(): void
    {
        $transfer = Proto::message(1, Proto::uint(1, 1_000_000)).Proto::message(2, Proto::bytes(1, Concordium::decodeAddress(self::PAY_TO)));
        $effects = Proto::message(5, $transfer);
        $details = Proto::message(3, $effects);
        $summary = Proto::message(3, Proto::bytes(1, hex2bin(self::HASH))).Proto::message(4, $details);
        $finalized = Proto::message(1, Proto::message(2, $summary));
        $this->answer(Proto::message(3, $finalized));

        $this->assertSame([
            'finalized' => true,
            'success' => true,
            'amount' => 1_000_000,
            'receiver' => self::PAY_TO,
            'transaction' => self::HASH,
        ], $this->node()->status(self::HASH));
    }

    public function testARejectedTransferIsFinalizedButNotSuccessful(): void
    {
        $effects = Proto::message(1, Proto::message(2, Proto::uint(1, 1)));
        $summary = Proto::message(3, Proto::bytes(1, hex2bin(self::HASH))).Proto::message(4, Proto::message(3, $effects));
        $this->answer(Proto::message(3, Proto::message(1, Proto::message(2, $summary))));

        $status = $this->node()->status(self::HASH);
        $this->assertTrue($status['finalized']);
        $this->assertFalse($status['success']);
        $this->assertNull($status['amount']);
    }

    public function testAnUnfinalizedTransactionIsNotSuccessful(): void
    {
        $this->answer(Proto::message(1, ''));
        $this->assertSame([
            'finalized' => false,
            'success' => false,
            'amount' => null,
            'receiver' => null,
            'transaction' => self::HASH,
        ], $this->node()->status(self::HASH));
    }

    public function testAStatusBodyThatIsNotProtobufIsAFacilitatorException(): void
    {
        $this->answer('<html>ok</html>');

        $this->expectException(FacilitatorException::class);
        $this->node()->status(self::HASH);
    }

    private function req(): array
    {
        return [
            'scheme' => 'exact', 'network' => ConcordiumExact::TESTNET_NETWORK, 'amount' => '1000000', 'asset' => 'CCD',
            'payTo' => self::PAY_TO, 'maxTimeoutSeconds' => 60, 'extra' => ['feePayer' => self::SPONSOR],
        ];
    }
}

final class RecordingHttpClient implements ClientInterface
{
    /** @var list<RequestInterface> */
    public array $requests = [];
    public string $answer = '';
    public string $status = '0';

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $factory = new Psr17Factory();
        $trailers = "grpc-status: {$this->status}\r\n";
        $body = "\0".pack('N', strlen($this->answer)).$this->answer."\x80".pack('N', strlen($trailers)).$trailers;

        return $factory->createResponse(200)->withBody($factory->createStream($body));
    }
}
