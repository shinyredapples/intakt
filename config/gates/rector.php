<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

$cacheBase = getenv('INTAKT_GATES_CACHE');
$cacheDirectory = (is_string($cacheBase) && '' !== $cacheBase ? $cacheBase : sys_get_temp_dir().'/intakt-gates').'/rector';

return RectorConfig::configure()
    ->withCache($cacheDirectory)
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
