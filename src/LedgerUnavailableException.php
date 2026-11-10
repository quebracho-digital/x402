<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The PaymentLedger could not read or write the payment's state (its store is down).
 *
 * Thrown by a ledger, caught by the middlewares before the handler runs: the answer is 503 ledger_unavailable
 * (HTTP) or an in-band isError ledger_unavailable (MCP). Nothing was charged, because the handler did not run.
 * Thrown after a settle that succeeded, the middlewares keep the response instead: the money already moved.
 */
final class LedgerUnavailableException extends \RuntimeException implements X402Exception
{
}
