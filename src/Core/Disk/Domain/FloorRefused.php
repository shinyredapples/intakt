<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Domain;

use InvalidArgumentException;

final class FloorRefused extends InvalidArgumentException
{
    // One rule for the boot pass and the service, because a floor of 0 would never hold and a text would compare as a number.
    public static function unlessAtLeastOne(string $parameter, mixed $value): void
    {
        if (!is_int($value) || $value < 1) {
            throw new self(sprintf('%s must be a whole number of at least 1, %s given', $parameter, is_int($value) ? (string) $value : get_debug_type($value)));
        }
    }
}
