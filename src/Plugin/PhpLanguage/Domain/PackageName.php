<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

// Composer installs a package to the vendor folder plus its name, so a name with another slash or a ".." step would be written outside it.
final class PackageName
{
    // Composer's rule for a package name, see https://getcomposer.org/doc/04-schema.md#name
    private const string PACKAGE = '{\A[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*\z}i';

    // No slash in a platform requirement, so one can never name a folder below the vendor folder.
    private const string PLATFORM = '{\A(?:(?:php|composer)(?:-[a-z0-9._-]+)?|(?:ext|lib)-[a-z0-9._-]+)\z}i';

    public static function isReadable(string $name): bool
    {
        return 1 === preg_match(self::PACKAGE, $name) || 1 === preg_match(self::PLATFORM, $name);
    }
}
