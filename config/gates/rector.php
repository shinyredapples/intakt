<?php

declare(strict_types=1);

use Intakt\Gates\GatesCache;
use Intakt\Gates\RectorPathsFile;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;

$pathsFile = getenv(RectorPathsFile::VARIABLE);
// Because the gate names its files in a paths file, a run started by hand without one takes its paths from the command line instead
$paths = is_string($pathsFile) ? array_map(static fn (string $path): string => getcwd().'/'.$path, RectorPathsFile::read($pathsFile)) : [];

return RectorConfig::configure()
    ->withPaths($paths)
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
