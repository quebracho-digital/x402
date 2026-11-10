<?php

declare(strict_types=1);

namespace Quebracho\X402\Tests;

use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * A PSR-6 pool in memory, for tests: no expiry.
 */
final class ArrayCache implements CacheItemPoolInterface
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function getItem(string $key): CacheItemInterface
    {
        return new class($key, \array_key_exists($key, $this->values), $this->values[$key] ?? null) implements CacheItemInterface {
            public function __construct(private readonly string $key, private readonly bool $hit, private mixed $value)
            {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function get(): mixed
            {
                return $this->value;
            }

            public function isHit(): bool
            {
                return $this->hit;
            }

            public function set(mixed $value): static
            {
                $this->value = $value;

                return $this;
            }

            public function expiresAt(?\DateTimeInterface $expiration): static
            {
                return $this;
            }

            public function expiresAfter(int|\DateInterval|null $time): static
            {
                return $this;
            }
        };
    }

    public function getItems(array $keys = []): iterable
    {
        return array_map($this->getItem(...), array_combine($keys, $keys));
    }

    public function hasItem(string $key): bool
    {
        return \array_key_exists($key, $this->values);
    }

    public function clear(): bool
    {
        $this->values = [];

        return true;
    }

    public function deleteItem(string $key): bool
    {
        unset($this->values[$key]);

        return true;
    }

    public function deleteItems(array $keys): bool
    {
        array_map($this->deleteItem(...), $keys);

        return true;
    }

    public function save(CacheItemInterface $item): bool
    {
        $this->values[$item->getKey()] = $item->get();

        return true;
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->save($item);
    }

    public function commit(): bool
    {
        return true;
    }
}
