<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git;

use InvalidArgumentException;

// The functions below sit in the namespaces PHP searches first for an unqualified preg_match(), so a test can make the module's calls throw while armed.
final class PregMatchThatThrows
{
    private static bool $armed = false;

    public static function arm(): void
    {
        self::$armed = true;
    }

    public static function disarm(): void
    {
        self::$armed = false;
    }

    public static function failWhenArmed(): void
    {
        if (self::$armed) {
            throw new InvalidArgumentException('unexpected');
        }
    }
}

namespace Intakt\Core\Git\Domain;

use Intakt\Tests\Core\Git\PregMatchThatThrows;

use function preg_match as nativePregMatch;

function preg_match(string $pattern, string $subject, mixed &$matches = null, int $flags = 0, int $offset = 0): int|false
{
    PregMatchThatThrows::failWhenArmed();

    return nativePregMatch($pattern, $subject, $matches, $flags, $offset);
}

namespace Intakt\Core\Git\UseCase\ListProtectedChanges;

use Intakt\Tests\Core\Git\PregMatchThatThrows;

use function preg_match as nativePregMatch;

function preg_match(string $pattern, string $subject, mixed &$matches = null, int $flags = 0, int $offset = 0): int|false
{
    PregMatchThatThrows::failWhenArmed();

    return nativePregMatch($pattern, $subject, $matches, $flags, $offset);
}
