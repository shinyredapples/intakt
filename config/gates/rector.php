<?php

declare(strict_types=1);

use Intakt\Gates\GatesCache;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

return RectorConfig::configure()
    ->withCache(GatesCache::directory().'/rector')
    ->withImportNames(removeUnusedImports: true)
    ->withPhpSets(php85: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    ->withRules([StringClassNameToClassConstantRector::class])
    ->withSkip([
        getcwd().'/upstream',
        getcwd().'/var',
        getcwd().'/vendor',
        getcwd().'/tests/Gates/Fixtures',
    ]);
