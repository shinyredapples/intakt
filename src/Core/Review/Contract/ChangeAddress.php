<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Only a GitHub pull-request address is lowercased, because GitHub does not tell owners or repositories apart by letter case, while git does tell local branches apart by it.
final readonly class ChangeAddress
{
    private const string GITHUB_PULL_REQUEST = '#^https://github\.com/[A-Za-z0-9-]+/(?!\.+/)[A-Za-z0-9_.-]+/pull/[1-9][0-9]*/?$#i';

    public static function canonical(string $address): string
    {
        return 1 === preg_match(self::GITHUB_PULL_REQUEST, $address) ? strtolower($address) : $address;
    }

    public static function sameChange(string $one, string $other): bool
    {
        return self::canonical($one) === self::canonical($other);
    }
}
