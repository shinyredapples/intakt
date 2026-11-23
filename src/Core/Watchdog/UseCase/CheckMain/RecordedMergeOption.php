<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Watchdog\Contract\RecordedMerge;

final readonly class RecordedMergeOption
{
    // A full commit name holds no dot, so exactly one separator splits the value without doubt; RecordedMerge refuses anything but full names.
    public static function parsed(string $value): RecordedMerge
    {
        $parts = explode('..', $value);
        if (2 !== count($parts)) {
            throw RecordedMergeNotARange::of($value);
        }
        [$base, $head] = $parts;

        return new RecordedMerge($head, $base);
    }
}
