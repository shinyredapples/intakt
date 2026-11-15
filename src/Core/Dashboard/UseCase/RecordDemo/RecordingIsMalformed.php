<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use RuntimeException;
use Throwable;

final class RecordingIsMalformed extends RuntimeException
{
    public static function because(Throwable $reason): self
    {
        return new self('The recording is not a recording of a review: '.$reason->getMessage(), 0, $reason);
    }

    public static function notARecording(): self
    {
        return new self('The recording is not a recording of a review.');
    }
}
