<?php

declare(strict_types=1);

use Intakt\Gates\GatesCache;
use Intakt\Gates\RectorGate;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

$pathsFile = getenv(RectorGate::PATHS_FILE_VARIABLE);
$paths = is_string($pathsFile) && is_file($pathsFile) ? file($pathsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;

return RectorConfig::configure()
    // The rector gate names its files here, not on the command line: see RectorGate::environment()
    ->withPaths(false === $paths ? [] : array_map(static fn (string $path): string => getcwd().'/'.$path, $paths))
    ->withCache(GatesCache::directory().'/rector')
    // As many workers as the CI runner's 4 vCPUs and 300 s each, so concurrent checks stop timing each other out
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
