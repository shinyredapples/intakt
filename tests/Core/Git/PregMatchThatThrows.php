<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git;

use InvalidArgumentException;

/**
 * Makes the Git module's unqualified preg_match() calls throw the SPL exception while armed, so a test
 * can prove that a narrowed catch lets an unexpected one through. The functions that PHP resolves
 * first live in the namespaces below, and call the real one while the switch is off.
 */
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

function preg_match(string $pattern, string $subject, mixed &$matches = null, int $flags = 0, int $offset = 0): int|false
{
    PregMatchThatThrows::failWhenArmed();

    return \preg_match($pattern, $subject, $matches, $flags, $offset);
}

namespace Intakt\Core\Git\UseCase\ListProtectedChanges;

use Intakt\Tests\Core\Git\PregMatchThatThrows;

function preg_match(string $pattern, string $subject, mixed &$matches = null, int $flags = 0, int $offset = 0): int|false
{
    PregMatchThatThrows::failWhenArmed();

    return \preg_match($pattern, $subject, $matches, $flags, $offset);
}
