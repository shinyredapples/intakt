<?php

declare(strict_types=1);

use Intakt\Gates\GatesCache;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withCache(GatesCache::directory().'/rector')
    // 4 workers because the CI runner has 4 vCPUs, and 300 s each so a starved worker can still finish:
    // otherwise several checks at once on one machine (a worker per core, 120 s) time each other's workers out
    ->withParallel(
        timeoutSeconds: 300,
        maxNumberOfProcess: 4,
    )
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
