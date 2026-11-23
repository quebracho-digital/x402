<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * One unary gRPC-web call to a Concordium node: plain HTTPS POSTs, no ext-grpc
 * and no generated protobuf classes.
 *
 * The body is a length-prefixed frame; the answer carries its message in a data
 * frame and its status in the trailers (`grpc-status`), which HTTP/1.1 may fold
 * into the response headers.
 *
 * The PSR-18 client must not follow redirects: the request carries a signed
 * transaction and, on settle, the sponsor's signature.
 */
final class GrpcWeb
{
    private readonly string $url;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        string $url,
    ) {
        if ('https' !== parse_url($url, \PHP_URL_SCHEME) || !parse_url($url, \PHP_URL_HOST)) {
            throw new \InvalidArgumentException("Not an https gRPC-web URL: $url");
        }
        $this->url = rtrim($url, '/');
    }

    /**
     * The message of one call; the node's non-zero status throws.
     *
     * @throws FacilitatorException the node cannot be reached or answered nothing
     * @throws GrpcException        the node answered with a non-zero gRPC status
     */
    public function call(string $service, string $method, string $body): string
    {
        $frame = "\0".pack('N', strlen($body)).$body;
        $request = $this->requests->createRequest('POST', $this->url.'/'.$service.'/'.$method)
            ->withHeader('content-type', 'application/grpc-web+proto')
            ->withHeader('x-grpc-web', '1')
            ->withHeader('te', 'trailers')
            ->withBody($this->streams->createStream($frame));
        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new FacilitatorException("Concordium node unreachable: {$e->getMessage()}", 0, $e);
        }
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new FacilitatorException("Concordium node answered HTTP {$response->getStatusCode()}.");
        }
        $data = null;
        $trailers = '';
        $length = 0;
        $raw = (string) $response->getBody();
        for ($i = 0; $i + 5 <= strlen($raw); $i += 5 + $length) {
            $flags = \ord($raw[$i]);
            $length = unpack('N', substr($raw, $i + 1, 4))[1];
            $payload = substr($raw, $i + 5, $length);
            if ($flags & 0x80) {
                $trailers .= $payload;
            } elseif (null === $data) {
                $data = $payload;
            }
        }
        $status = $response->getHeaderLine('grpc-status');
        $message = $response->getHeaderLine('grpc-message');
        if ('' === $status && preg_match('/grpc-status:\s*(\d+)/i', $trailers, $m)) {
            $status = $m[1];
        }
        if ('' === $message && preg_match('/grpc-message:\s*(.*)/i', $trailers, $m)) {
            $message = rawurldecode(trim($m[1]));
        }
        if ('' !== $status && '0' !== $status) {
            throw new GrpcException((int) $status, '' !== $message ? $message : "Concordium node gRPC status $status.");
        }
        if (null === $data) {
            throw new FacilitatorException('Concordium node answered without a message.');
        }

        return $data;
    }
}
