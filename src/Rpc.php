<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * One chain's JSON-RPC node: `$rpc('eth_call', [...])` returns the result.
 *
 * An answer with an error (a revert, a rejected transaction) throws
 * \RuntimeException with the node's message; an unreachable node throws
 * FacilitatorException.
 */
final class Rpc
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        private readonly string $url,
    ) {
    }

    public function __invoke(string $method, array $params): mixed
    {
        $request = $this->requests->createRequest('POST', $this->url)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->streams->createStream(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], \JSON_THROW_ON_ERROR)));
        try {
            $response = json_decode((string) $this->http->sendRequest($request)->getBody(), true);
        } catch (ClientExceptionInterface $e) {
            throw new FacilitatorException("$method: ".$e->getMessage(), 0, $e);
        }
        if (!is_array($response)) {
            throw new FacilitatorException("$method: not a JSON-RPC answer");
        }
        if (isset($response['error'])) {
            throw new \RuntimeException("$method: ".(is_array($response['error']) ? (string) ($response['error']['message'] ?? 'error') : 'error'));
        }

        // A null result is an answer: a receipt not mined yet.
        return array_key_exists('result', $response) ? $response['result'] : throw new FacilitatorException("$method: no result");
    }

    /**
     * A 0x-hex quantity as a number.
     */
    public static function number(string $hex): \GMP
    {
        return gmp_init(ltrim(substr($hex, 2), '0') ?: '0', 16);
    }

    /**
     * Hex (an address or a number) left-padded to a 32-byte ABI word.
     */
    public static function word(string $hex): string
    {
        return str_pad(strtolower(str_starts_with($hex, '0x') ? substr($hex, 2) : $hex), 64, '0', \STR_PAD_LEFT);
    }
}
