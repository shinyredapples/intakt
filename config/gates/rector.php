<?php

declare(strict_types=1);

use Intakt\Gates\GatesCache;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withCache(GatesCache::directory().'/rector')
    // At most 4 workers, as many as the CI runner's vCPUs, and 300 s per worker: with Rector's defaults (a worker per
    // core, 120 s) several checks running at once on one machine starve each other's workers into timeouts.
    ->withParallel(timeoutSeconds: 300, maxNumberOfProcess: 4)
    ->withImportNames(removeUnusedImports: true)
    ->withPhpSets(php85: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
    )
    ->withRules([StringClassNameToClassConstantRector::class])
    ->withSkip([
        getcwd().'/upstream',
        getcwd().'/var',
        getcwd().'/vendor',
        getcwd().'/tests/Gates/Fixtures',
    ]);
