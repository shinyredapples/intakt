<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use InvalidArgumentException;

final class RecordedMergeNotARange extends InvalidArgumentException
{
    public static function of(string $value): self
    {
        return new self(sprintf('The recorded merge "%s" is not <base>..<head>, two full commit names around two dots.', $value));
    }
}
