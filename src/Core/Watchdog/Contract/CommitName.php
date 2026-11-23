<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class CommitName
{
    // Full names only (SHA-1 or SHA-256), because an abbreviated name can match a different commit as the history grows.
    private const string FULL = '/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/';

    public static function full(string $name, string $role): string
    {
        if (1 !== preg_match(self::FULL, $name)) {
            throw new CommitNameNotFull(sprintf('The %s "%s" is not a full commit name.', $role, $name));
        }

        return $name;
    }

    // Measured against the repository's own name length, because git reads a SHA-256 name cut to 40 characters as an abbreviation.
    public static function fullOfLength(string $name, string $role, int $length): string
    {
        if (strlen(self::full($name, $role)) !== $length) {
            throw new CommitNameNotFull(sprintf('The %s "%s" is not a full commit name of this repository (%d characters).', $role, $name, $length));
        }

        return $name;
    }
}
