<?php

declare(strict_types=1);

namespace Quebracho\X402;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The facilitator a one-line setting names: what an application reads from its configuration.
 */
final class Facilitators
{
    public const string MOCK = 'mock';

    /**
     * "mock" is MockFacilitator (nothing moves); Circle's URL is CircleFacilitator (its /status poll, and a
     * seller proof when there is no key); any other https URL is an HttpFacilitator.
     *
     * The HTTP client must not follow redirects: the signed payment goes to this host only.
     *
     * @param ?string $bearer the facilitator's API key; NULL or '' sends none
     * @param ?Signer $seller Circle without a key: the payTo key, which signs the seller proof
     *
     * @throws \InvalidArgumentException when $setting is neither "mock" nor an https URL. The message shows only
     *                                   its scheme and host: a URL may carry a password or a token
     */
    public static function from(
        string $setting,
        ClientInterface $http,
        RequestFactoryInterface $requests,
        StreamFactoryInterface $streams,
        #[\SensitiveParameter] ?string $bearer = null,
        #[\SensitiveParameter] ?Signer $seller = null,
    ): Facilitator {
        $setting = trim($setting);
        $bearer = '' === $bearer ? null : $bearer;
        if (self::MOCK === $setting) {
            return new MockFacilitator();
        }
        try {
            return self::normalize($setting) === self::normalize(CircleFacilitator::URL)
                ? new CircleFacilitator($http, $requests, $streams, $bearer, $seller)
                : new HttpFacilitator($http, $requests, $streams, $setting, $bearer);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no facilitator: use "mock" or an https:// URL.', self::origin($setting)));
        }
    }

    /** The URL as compared with Circle's: scheme and host in lower case, no query, fragment or trailing slash. */
    private static function normalize(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['host'])) {
            return rtrim($url, '/');
        }

        return strtolower($parts['scheme'] ?? '').'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').rtrim($parts['path'] ?? '', '/');
    }

    /** scheme://host[:port] of a URL, without userinfo, path or query; "(unparseable)" when there is no such thing. */
    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '(unparseable)';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
