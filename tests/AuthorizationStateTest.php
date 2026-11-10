<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Quebracho\X402\AuthorizationState;
use Quebracho\X402\Networks;

/**
 * The token's authorizationState(from, nonce), read with one eth_call. The node is a fake.
 */
final class AuthorizationStateTest extends TestCase
{
    private const FROM = '0x2c7536E3605D9C16a7a3D7b1898e529396a65c23';
    private const NONCE = '0x00000000000000000000000000000000000000000000000000000000000000ff';

    /** @var list<array<string, mixed>> the JSON-RPC calls the node received */
    private array $calls = [];

    private function state(string|int $answer): AuthorizationState
    {
        $http = new class($answer, $this->calls) implements ClientInterface {
            public function __construct(private readonly string|int $answer, private array &$calls)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->calls[] = ['url' => (string) $request->getUri()] + json_decode((string) $request->getBody(), true);
                if (\is_int($this->answer)) {
                    return new Response($this->answer);
                }

                return new Response(200, [], json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $this->answer]));
            }
        };
        $f = new Psr17Factory();

        return new AuthorizationState($http, $f, $f);
    }

    public function testAUsedNonceIsTrueAndAnUnusedOneFalse(): void
    {
        self::assertTrue($this->state('0x'.str_pad('1', 64, '0', \STR_PAD_LEFT))('eip155:84532', self::FROM, self::NONCE));
        self::assertFalse($this->state('0x'.str_repeat('0', 64))('eip155:84532', self::FROM, self::NONCE));
    }

    public function testItAsksTheTokenOfTheNetworkAtItsNode(): void
    {
        $this->state('0x'.str_repeat('0', 64))('eip155:84532', self::FROM, self::NONCE);
        $token = Networks::get('eip155:84532');

        self::assertSame($token['rpc'], $this->calls[0]['url']);
        self::assertSame('eth_call', $this->calls[0]['method']);
        self::assertSame($token['asset'], $this->calls[0]['params'][0]['to']);
        self::assertSame(AuthorizationState::SELECTOR.str_pad(strtolower(substr(self::FROM, 2)), 64, '0', \STR_PAD_LEFT).substr(self::NONCE, 2), strtolower($this->calls[0]['params'][0]['data']));
        self::assertSame('latest', $this->calls[0]['params'][1]);
    }

    public function testAnythingThatIsNoEip3009AuthorizationIsRefusedBeforeTheNodeIsAsked(): void
    {
        try {
            $this->state('0x'.str_repeat('0', 64))('eip155:84532', 'alice.near', self::NONCE);
            self::fail('not an address');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->calls);
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->state('0x'.str_repeat('0', 64))('eip155:84532', self::FROM, '0x01');
    }

    public function testANodeThatDoesNotAnswerABoolThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->state('0x')('eip155:84532', self::FROM, self::NONCE);
    }

    public function testANodeThatIsDownThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->state(503)('eip155:84532', self::FROM, self::NONCE);
    }
}
