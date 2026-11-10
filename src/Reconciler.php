<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Corrects the ledger's open payments ('settling', 'pending': the money may have moved, nobody knows) with what
 * the chain says. Run it from cron, every few minutes. It never delivers nor charges: it writes 'settled' or frees
 * the claim, and a buyer's retry then goes through the middlewares as usual (replay, upfront run, lookup).
 *
 * Every RPC question is asked before any write, so a node that fails changes nothing. EVM (EIP-3009) payments
 * only: any other is reported untouched, to reconcile by hand. Log the rows it returns: needs_review and
 * rpc_error are the ones a person must look at.
 */
final class Reconciler
{
    /** authorizationState(address,bytes32), EIP-3009. */
    private const string SELECTOR = '0xe94a0102';
    /** After validBefore, an unused authorization can no longer move money; 60 s more for clocks and blocks. */
    public const int GRACE = 60;
    /** An entry open longer than this is reported needs_review when it is not resolved. */
    public const int REVIEW_AFTER = 86400;
    /**
     * How far back eth_getLogs looks for AuthorizationUsed: 10,000 blocks, the range public nodes and most
     * providers accept in one call. That is about 5.5 h on Base (2 s), 33 h on Ethereum (12 s). The event is
     * mined before validBefore and the cron runs every 5 minutes, so it is inside unless the reconciler did
     * not run for that long; then it is needs_review, found by hand with an explorer.
     */
    public const int LOG_BLOCKS = 10000;

    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    /**
     * One row per open key; result is settled, released, needs_review, untouched or rpc_error.
     *
     * @return list<array{key: string, status: string, result: string, detail: string}>
     */
    public function reconcile(int $limit): array
    {
        $out = [];
        foreach ($this->ledger->openKeys($limit) as $key => $ref) {
            try {
                [$result, $detail] = $this->one($key, $ref);
            } catch (\Throwable $e) {
                // A node or a ledger that failed: whatever was not written stays as it was.
                [$result, $detail] = ['untouched', $e->getMessage()];
            }
            $opened = is_int($ref['opened'] ?? null) ? $ref['opened'] : time();
            if (\in_array($result, ['untouched', 'rpc_error'], true) && time() - $opened > self::REVIEW_AFTER) {
                [$result, $detail] = ['needs_review', 'open for more than 24 h: '.$detail];
            }
            $out[] = ['key' => $key, 'status' => \is_string($ref['status'] ?? null) ? $ref['status'] : '?', 'result' => $result, 'detail' => $detail];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $ref
     *
     * @return array{0: string, 1: string}
     */
    private function one(string $key, array $ref): array
    {
        $state = $this->ledger->get($key);
        $status = $state['status'] ?? null;
        if (null !== $state && !\in_array($status, ['settling', 'pending'], true)) {
            // Settled, delivered or handling since (a close that failed), or claimed again: nothing to correct.
            $this->ledger->close($key);

            return ['untouched', "the state is already '".(\is_string($status) ? $status : '?')."'; entry closed"];
        }
        $network = \is_string($ref['network'] ?? null) ? $ref['network'] : '';
        $asset = $ref['asset'] ?? null;
        $from = $ref['from'] ?? null;
        $nonce = $ref['nonce'] ?? null;
        if (!str_starts_with($network, 'eip155:') || !self::hex($asset, 40) || !self::hex($from, 40) || !self::hex($nonce, 64)) {
            return ['untouched', 'not an EIP-3009 payment: reconcile it by hand'];
        }
        $url = Networks::find($network)['rpc'] ?? null;
        if (!\is_string($url) || '' === $url) {
            return ['rpc_error', "no rpc declared for $network"];
        }
        $rpc = new Rpc($this->http, $this->requests, $this->streams, $url);

        $used = $rpc('eth_call', [['to' => $asset, 'data' => self::SELECTOR.Rpc::word($from).Rpc::word($nonce)], 'latest']);
        if (!self::hex($used, 64)) {
            throw new \UnexpectedValueException('authorizationState did not answer a bool word');
        }
        if (0 === gmp_cmp(Rpc::number($used), 0)) {
            $validBefore = $ref['validBefore'] ?? null;
            if (!\is_int($validBefore) || time() <= $validBefore + self::GRACE) {
                return ['untouched', 'authorization unused and still valid'];
            }

            return $this->unchanged($key, $status) ? $this->release($key) : ['untouched', 'the state changed while asking'];
        }

        $tx = self::hex($ref['transaction'] ?? null, 64) ? $ref['transaction'] : null;
        if (null !== $tx) {
            $mined = $rpc('eth_getTransactionReceipt', [$tx]);
            $outcome = \is_array($mined) ? ($mined['status'] ?? null) : null;
            if ('0x0' === $outcome) {
                return ['needs_review', "authorization used, but $tx reverted"];
            }
            if ('0x1' !== $outcome) {
                // Not mined: another transaction used the authorization; its event says which.
                $tx = null;
            }
        }
        $tx ??= $this->event($rpc, $asset, $from, $nonce);
        if (null === $tx) {
            return ['needs_review', 'authorization used, no AuthorizationUsed event in the last '.self::LOG_BLOCKS.' blocks'];
        }
        if (!$this->unchanged($key, $status)) {
            return ['untouched', 'the state changed while asking'];
        }
        $this->settle($key, $ref, $state, $tx, $network, $from);

        return ['settled', $tx];
    }

    /**
     * The transaction that logged the asset's AuthorizationUsed(from, nonce), or NULL.
     */
    private function event(Rpc $rpc, string $asset, string $from, string $nonce): ?string
    {
        $latest = $rpc('eth_blockNumber', []);
        if (!\is_string($latest) || 1 !== preg_match('/^0x[0-9a-fA-F]+$/', $latest)) {
            throw new \UnexpectedValueException('eth_blockNumber did not answer a number');
        }
        $start = gmp_sub(Rpc::number($latest), self::LOG_BLOCKS);
        $start = gmp_cmp($start, 0) < 0 ? gmp_init(0) : $start;
        $logs = $rpc('eth_getLogs', [[
            'address' => $asset,
            'fromBlock' => '0x'.gmp_strval($start, 16),
            'toBlock' => 'latest',
            'topics' => [ReceiptLookup::AUTHORIZATION_USED, '0x'.Rpc::word($from), strtolower($nonce)],
        ]]);
        if (!\is_array($logs)) {
            throw new \UnexpectedValueException('eth_getLogs did not answer a list');
        }
        foreach ($logs as $log) {
            if (\is_array($log) && \is_string($log['address'] ?? null) && 0 === strcasecmp($log['address'], $asset) && self::hex($log['transactionHash'] ?? null, 64)) {
                return $log['transactionHash'];
            }
        }

        return null;
    }

    /**
     * Writes the state Sale::confirm() would: the chain's receipt, 'delivered' when a response is kept, else
     * 'settled'; upfront, handler_ran false (the handler runs after the settle, so it has not run). Default flow
     * without a kept response, a retry answers already_settled: the reconciler cannot reproduce the answer.
     *
     * @param array<string, mixed>  $ref
     * @param ?array<string, mixed> $state
     */
    private function settle(string $key, array $ref, ?array $state, string $tx, string $network, string $from): void
    {
        $state ??= [];
        $state['receipt'] = Settlement::settled(['success' => true, 'transaction' => $tx, 'network' => $network, 'payer' => $from] + array_filter(['paymentId' => $ref['paymentId'] ?? null], 'is_string'));
        if (true === ($ref['upfront'] ?? null)) {
            $state['handler_ran'] = false;
        }
        $state['status'] = isset($state['response']) ? 'delivered' : 'settled';
        // The TTL a settled state gets from Sale: until the signature expires, at most Settlement::TTL.
        $this->ledger->put($key, $state, Settlement::ttlUntil(is_numeric($ref['validBefore'] ?? null) ? (int) $ref['validBefore'] : null, $state));
        $this->ledger->close($key);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function release(string $key): array
    {
        // Release deletes the state and closes its entry (PaymentLedger::release).
        $this->ledger->release($key);

        return ['released', 'authorization unused and expired: never charged'];
    }

    /**
     * Read again just before writing: a buyer's retry may have resolved it while the chain was asked.
     * ponytail: not atomic; a write in between the read and ours is lost, and the next run sees the result.
     */
    private function unchanged(string $key, mixed $status): bool
    {
        return ($this->ledger->get($key)['status'] ?? null) === $status;
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private static function hex(mixed $value, int $digits): bool
    {
        return \is_string($value) && 1 === preg_match('/^0x[0-9a-fA-F]{'.$digits.'}$/', $value);
    }
}
