<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    // Optional (composer.json "suggest"): only RedisLedger needs it, and it says so when it is missing.
    ->ignoreErrorsOnExtension('ext-redis', [ErrorType::SHADOW_DEPENDENCY])
    // Optional (composer.json "suggest"): Stop checks the owner of its file only when posix_geteuid exists.
    ->ignoreErrorsOnExtension('ext-posix', [ErrorType::SHADOW_DEPENDENCY])
    // Tests only: FileJobStoreTest forks to race two writers.
    ->ignoreErrorsOnExtensionAndPath('ext-pcntl', __DIR__.'/tests', [ErrorType::SHADOW_DEPENDENCY]);
