<?php

declare(strict_types=1);

namespace Intakt\Plugin\PhpLanguage\Domain;

// Backed by the lock file's own keys, so a group reads its packages under its value.
enum PackageGroup: string
{
    case Main = 'packages';
    case Development = 'packages-dev';
}
