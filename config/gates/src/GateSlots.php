<?php

declare(strict_types=1);

namespace Intakt\Gates;

use InvalidArgumentException;

final readonly class GateSlots
{
    public const string ENVIRONMENT = 'INTAKT_GATES_AT_ONCE';

    public static function fromEnvironment(): int
    {
        $override = getenv(self::ENVIRONMENT);

        if (false === $override || '' === $override) {
            return self::processors();
        }

        $slots = filter_var($override, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (false === $slots) {
            throw new InvalidArgumentException(self::ENVIRONMENT.' must be a whole number of at least 1, got '.$override);
        }

        return $slots;
    }

    private static function processors(): int
    {
        return max(1, posix_sysconf(POSIX_SC_NPROCESSORS_ONLN));
    }
}
