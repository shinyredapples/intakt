<?php

declare(strict_types=1);

use Intakt\Gates\GatesCache;
use Intakt\Gates\PhpCsFixer\MultilineNamedArgumentsFixer;
use Intakt\Gates\PhpCsFixer\PlainFirstClassCallableFixer;
use PhpCsFixer\Config;

return new Config()
    ->setRiskyAllowed(true)
    ->setCacheFile(GatesCache::directory().'/php-cs-fixer.cache')
    ->registerCustomFixers([new PlainFirstClassCallableFixer(), new MultilineNamedArgumentsFixer()])
    ->setRules([
        'Intakt/plain_first_class_callable' => true,
        'Intakt/multiline_named_arguments' => true,
        '@PER-CS' => true,
        '@Symfony' => true,
        'declare_strict_types' => true,
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => false, 'remove_inheritdoc' => true],
        'native_function_invocation' => ['include' => [], 'strict' => true],
        'native_constant_invocation' => ['fix_built_in' => false, 'include' => [], 'strict' => true],
        'global_namespace_import' => ['import_classes' => true, 'import_constants' => false, 'import_functions' => false],
        'fully_qualified_strict_types' => ['import_symbols' => true, 'leading_backslash_in_global_namespace' => false],
        'no_leading_import_slash' => true,
        'no_unused_imports' => true,
        'no_useless_else' => true,
        'php_unit_test_annotation' => ['style' => 'prefix'],
        'php_unit_data_provider_static' => true,
        'ordered_class_elements' => [
            'order' => ['use_trait', 'case', 'constant', 'property', 'construct', 'method_public', 'method_protected', 'method_private'],
            'sort_algorithm' => 'none',
        ],
        'ordered_imports' => ['imports_order' => ['class', 'function', 'const'], 'sort_algorithm' => 'alpha'],
    ]);
