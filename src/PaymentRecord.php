<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The seller's own record of its sales and its failed settlements, a PaymentOutcome listener: one JSON line
 * per payment, appended to $dir/<YYYY-MM>.jsonl. Unlike a log (which a host may rotate or drop), these files are
 * kept: nothing here rotates or deletes them.
 *
 * Kept: what moved money or should have (OUTCOMES). A payment refused before the tool ran (rejected) or a
 * tool that delivered nothing (failed) charged nothing, and stays in the log only, so a flood of bad
 * signatures cannot fill the disk.
 *
 * Each line: when (UTC), the outcome, the settlement key (what the ledger and Reconciler use), the
 * network, payTo, amount and asset offered, the payer, the transaction and the reason. Never the signature,
 * the response body or the exception (it may hold internals). failed_after_settle is the line to act on:
 * charged, nothing delivered, a refund by hand.
 *
 * A write that fails is logged and the payment goes on: the record must not refuse a sale that happened.
 */
final class PaymentRecord
{
    public const array OUTCOMES = ['settled', 'delivered', 'not_settled', 'failed_after_settle'];

    public function __construct(
        private readonly string $dir,
        private readonly ?LoggerInterface $logger = null,
        private readonly ClockInterface|\Closure|null $clock = null,
    ) {
    }

    public function __invoke(PaymentOutcome $event): void
    {
        if (!\in_array($event->status, self::OUTCOMES, true)) {
            return;
        }
        $now = Clock::now($this->clock);
        try {
            $key = Settlement::key($event->payment);
        } catch (\Throwable) {
            // ponytail: unreachable for a payment that settled; the line is kept without it.
            $key = null;
        }
        $from = $event->payment['payload']['authorization']['from'] ?? null;
        $line = [
            'at' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'status' => $event->status,
            'key' => $key,
            'network' => $event->req['network'] ?? null,
            'payTo' => $event->req['payTo'] ?? null,
            'amount' => $event->req['amount'] ?? null,
            'asset' => $event->req['asset'] ?? null,
            'payer' => $event->receipt['payer'] ?? (\is_string($from) ? $from : null),
            'transaction' => $event->receipt['transaction'] ?? null,
            'reason' => $event->reason ?? ($event->receipt['errorReason'] ?? null),
            'handlerStatus' => $event->handlerStatus,
        ];
        $file = $this->dir.'/'.gmdate('Y-m', $now).'.jsonl';
        try {
            if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                throw new \RuntimeException("Cannot create {$this->dir}.");
            }
            // One write under an exclusive lock: concurrent requests never interleave their lines.
            if (false === @file_put_contents($file, json_encode($line, \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND | \LOCK_EX)) {
                throw new \RuntimeException("Cannot write $file.");
            }
        } catch (\Throwable $e) {
            $this->logger?->error('x402 record not written: '.$e->getMessage(), $line);
        }
    }
}
