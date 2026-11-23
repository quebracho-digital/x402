<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * A gRPC answer that is not status 0, with the status the node sent.
 */
final class GrpcException extends \RuntimeException implements X402Exception
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
