<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class PhpLanguageServices
{
    public const string TRUSTED_VENDORS = 'intakt.php_language.trusted_vendors';

    public const string PACKAGE_TYPES = 'intakt.php_language.package_types';
}
