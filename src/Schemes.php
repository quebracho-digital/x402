<?php

declare(strict_types=1);

namespace Quebracho\X402;

/**
 * Which of several schemes handles one offer: its scheme name and its network both, since two schemes
 * (`exact` and `auth-capture`) share the EVM networks.
 */
final class Schemes
{
    /**
     * @param Scheme[] $schemes
     */
    public static function pick(array $schemes, array $req): ?Scheme
    {
        foreach ($schemes as $scheme) {
            if ($scheme->name() === ($req['scheme'] ?? null) && $scheme->supports((string) ($req['network'] ?? ''))) {
                return $scheme;
            }
        }

        return null;
    }
}
