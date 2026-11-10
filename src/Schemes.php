<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Finds the scheme of a network: among the ones given first, then among the library's own. The one place that
 * knows which schemes the library ships, so nothing else has to ask what family a network belongs to.
 *
 * @internal
 */
final class Schemes
{
    /** @var ?list<Scheme> */
    private static ?array $builtIn = null;

    /**
     * @template T of object
     *
     * @param iterable<Scheme>     $schemes the caller's own, asked first
     * @param class-string<T>|null $as      only a scheme that is also this (an optional interface: SpendsOnce,
     *                                      Expires, AddressForm, VetsOffers); the caller's own scheme that is not
     *                                      falls back on the library's for the same network
     *
     * @return ($as is null ? ?Scheme : ?T)
     */
    public static function find(string $network, iterable $schemes = [], ?string $as = null): ?object
    {
        foreach ($schemes as $scheme) {
            if ($scheme->supports($network) && (null === $as || $scheme instanceof $as)) {
                return $scheme;
            }
        }
        // Without keys: enough to read a payment, never to sign one.
        self::$builtIn ??= [new EvmExact(), new SolanaExact(), new NearExact(), new CasperExact()];
        foreach (self::$builtIn as $scheme) {
            if ($scheme->supports($network) && (null === $as || $scheme instanceof $as)) {
                return $scheme;
            }
        }

        return null;
    }
}
