<?php

declare(strict_types=1);

use PhpCsFixer\Config;

$cacheBase = getenv('INTAKT_GATES_CACHE');
$cacheDirectory = (is_string($cacheBase) && '' !== $cacheBase ? $cacheBase : sys_get_temp_dir().'/intakt-gates').'/php-cs-fixer';

return new Config()
    ->setRiskyAllowed(true)
    ->setCacheFile($cacheDirectory.'.cache')
    ->setRules([
        '@PER-CS' => true,
        '@Symfony' => true,
        'declare_strict_types' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => false, 'remove_inheritdoc' => true],
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'fully_qualified_strict_types' => ['import_symbols' => true, 'leading_backslash_in_global_namespace' => false],
        'no_leading_import_slash' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
    ]);
