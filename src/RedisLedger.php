<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * The PaymentLedger for several hosts: Redis (ext-redis), one JSON value per payment under 'x402:'.
 *
 * Claim is SET NX EX, so of N concurrent claims exactly one wins on any host. A Redis that does not answer
 * throws LedgerUnavailableException, which the middlewares answer as ledger_unavailable. Never fall back to
 * per-host files (FileLedger) when it is down: one payment could then be delivered once per host.
 *
 * Run Redis with maxmemory-policy noeviction and appendonly yes: a ledger that drops a key forgets a payment.
 */
final class RedisLedger implements PaymentLedger
{
    private const string PREFIX = 'x402:';
    /** The set of open keys (PaymentLedger::open); each one's ref is under OPEN.':'.$key. */
    private const string OPEN = 'x402:open';

    private ?\Redis $redis = null;

    /**
     * @param string $url redis[s]://[user][:password@]host[:port][/db]
     */
    public function __construct(private readonly string $url)
    {
    }

    public function claim(string $key, int $ttl): bool
    {
        return $this->call(fn (\Redis $r) => true === $r->set(self::PREFIX.$key, self::encode(['status' => 'claimed']), ['nx', 'ex' => $ttl]));
    }

    public function get(string $key): ?array
    {
        $raw = $this->call(fn (\Redis $r) => $r->get(self::PREFIX.$key));
        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    public function put(string $key, array $state, int $ttl): void
    {
        $this->call(fn (\Redis $r) => $r->set(self::PREFIX.$key, self::encode($state), ['ex' => $ttl]));
    }

    public function release(string $key): void
    {
        $this->call(fn (\Redis $r) => $r->del(self::PREFIX.$key));
        $this->close($key);
    }

    /**
     * The ref is a JSON value under 'x402:open:<key>' with its own TTL; the key is a member of the set 'x402:open'.
     */
    public function open(string $key, array $ref, int $ttl): void
    {
        $before = $this->call(fn (\Redis $r) => $r->get(self::OPEN.':'.$key));
        $before = is_string($before) ? json_decode($before, true) : null;
        $ref['opened'] = is_int($before['opened'] ?? null) ? $before['opened'] : (is_int($ref['opened'] ?? null) ? $ref['opened'] : time());
        $this->call(fn (\Redis $r) => $r->set(self::OPEN.':'.$key, self::encode($ref), ['ex' => $ttl]));
        $this->call(fn (\Redis $r) => $r->sAdd(self::OPEN, $key));
    }

    /**
     * A random sample of the set (SRANDMEMBER), so the same $limit entries cannot hide the rest run after run.
     * A member whose ref expired is removed from the set.
     */
    public function openKeys(int $limit): array
    {
        $keys = $this->call(fn (\Redis $r) => $r->sRandMember(self::OPEN, $limit));
        $keys = is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
        if ([] === $keys) {
            return [];
        }
        $refs = $this->call(fn (\Redis $r) => $r->mGet(array_map(static fn (string $k) => self::OPEN.':'.$k, $keys)));
        $out = [];
        foreach ($keys as $i => $key) {
            $ref = is_array($refs) && is_string($refs[$i] ?? null) ? json_decode($refs[$i], true) : null;
            if (is_array($ref)) {
                $out[$key] = $ref;
            } else {
                $this->call(fn (\Redis $r) => $r->sRem(self::OPEN, $key));
            }
        }

        return $out;
    }

    public function close(string $key): void
    {
        $this->call(fn (\Redis $r) => $r->del(self::OPEN.':'.$key));
        $this->call(fn (\Redis $r) => $r->sRem(self::OPEN, $key));
    }

    /**
     * Throws unless Redis answers PING: for a deploy check.
     */
    public function ping(): void
    {
        $answer = $this->call(fn (\Redis $r) => $r->ping());
        if (!in_array($answer, [true, '+PONG', 'PONG'], true)) {
            throw new LedgerUnavailableException('Redis ledger: Redis did not answer PING.');
        }
    }

    /**
     * Runs one Redis operation. phpredis returns false for an error reply as well as for a miss (GET) or a
     * refused NX (SET), so an error is told apart by getLastError(), cleared before each call. A failed
     * operation drops the connection, so the next call connects again.
     */
    private function call(\Closure $operation): mixed
    {
        try {
            $redis = $this->connection();
            $redis->clearLastError();
            $answer = $operation($redis);
            if (false === $answer && null !== $error = $redis->getLastError()) {
                throw new \RedisException($error);
            }

            return $answer;
        } catch (\RedisException $e) {
            $this->redis = null;

            throw new LedgerUnavailableException('Redis ledger: Redis does not answer: '.$e->getMessage(), 0, $e);
        }
    }

    private function connection(): \Redis
    {
        if (null !== $this->redis) {
            return $this->redis;
        }
        $parts = parse_url($this->url);
        $scheme = is_array($parts) ? strtolower($parts['scheme'] ?? '') : '';
        if (!is_array($parts) || '' === ($parts['host'] ?? '') || !in_array($scheme, ['redis', 'rediss'], true)) {
            // The URL itself is not echoed: it may carry a password. Unavailable, not a 500 on every payment.
            throw new LedgerUnavailableException('Redis ledger: the URL must look like redis://host:port (or rediss://).');
        }
        if (!class_exists(\Redis::class)) {
            throw new LedgerUnavailableException('Redis ledger: ext-redis is not loaded.');
        }
        $redis = new \Redis();
        try {
            // Connect and read both time out at 2 s: a Redis that stops answering fails the request, it does not hang it.
            // A missing port is Redis's default, 6379. @: an unresolvable host also raises a PHP warning, which a debug
            // error handler turns into an ErrorException (a 500); the RedisException that follows says the same.
            @$redis->connect(('rediss' === $scheme ? 'tls://' : '').$parts['host'], $parts['port'] ?? 6379, 2.0, null, 0, 2.0);
            if (isset($parts['pass'])) {
                $auth = isset($parts['user']) ? [rawurldecode($parts['user']), rawurldecode($parts['pass'])] : rawurldecode($parts['pass']);
                if (true !== $redis->auth($auth)) {
                    throw new \RedisException('AUTH was refused: '.($redis->getLastError() ?? 'no reason given'));
                }
            }
            $db = (int) trim($parts['path'] ?? '', '/');
            if ($db > 0 && true !== $redis->select($db)) {
                // An out-of-range db would otherwise silently use db 0.
                throw new \RedisException('SELECT '.$db.' was refused: '.($redis->getLastError() ?? 'no reason given'));
            }
        } catch (\RedisException $e) {
            throw new LedgerUnavailableException('Redis ledger: Redis does not answer: '.$e->getMessage(), 0, $e);
        }

        return $this->redis = $redis;
    }

    private static function encode(array $state): string
    {
        return json_encode($state, JSON_THROW_ON_ERROR);
    }
}
