<?php

declare(strict_types=1);

namespace Quebracho\X402\Job;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Goes right outside X402McpMiddleware in the middleware stack: a `_start` call that the library refused
 * once its job row existed gets the jobId in the PaymentRequired it answers (structuredContent.jobId, and the
 * same JSON in content[0].text), so the buyer polls `_status` instead of retrying a deposit whose signature
 * may have expired. Who gets it is JobDeposit::jobIdFor's call: only the deposit's payer.
 *
 * Only a JSON answer with an x402 refusal is touched: a success already carries the jobId, and anything
 * else (a stream, a JSON-RPC error) goes as it came.
 */
final class JobIdOnRefusal implements MiddlewareInterface
{
    public function __construct(
        private readonly JobDeposit $deposit,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $body = $request->getBody();
        $message = json_decode((string) $body, true);
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $response = $handler->handle($request);
        if (!\is_array($message) || 'tools/call' !== ($message['method'] ?? null) || !\is_array($message['params'] ?? null)
            || !str_contains(strtolower($response->getHeaderLine('Content-Type')), 'application/json') || !$response->getBody()->isSeekable()) {
            return $response;
        }
        $out = json_decode((string) $response->getBody(), true);
        $response->getBody()->rewind();
        $result = \is_array($out) ? ($out['result'] ?? null) : null;
        $required = \is_array($result) ? ($result['structuredContent'] ?? null) : null;
        if (!\is_array($out) || !\is_array($result) || true !== ($result['isError'] ?? null) || !\is_array($required) || !isset($required['x402Version'], $required['error'])) {
            return $response;
        }
        $jobId = $this->deposit->jobIdFor($message['params']);
        if (null === $jobId) {
            return $response;
        }
        $required['jobId'] = $jobId;
        $result['structuredContent'] = $required;
        $result['content'] = [['type' => 'text', 'text' => json_encode($required, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)]];
        $out['result'] = $result;

        return $response->withBody($this->streams->createStream(json_encode($out, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)));
    }
}
