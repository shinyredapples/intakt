<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use RuntimeException;

final class RecordingNeedsCapturedUpdates extends RuntimeException
{
    public static function notCaptured(): self
    {
        return new self(sprintf('Set %s to the file the live updates were captured in.', CapturedUpdates::CAPTURE_VARIABLE));
    }

    public static function unreadable(string $capture, string $reason): self
    {
        return new self(sprintf('The live updates captured in %s cannot be read: %s', $capture, $reason));
    }

    public static function malformed(string $capture, int $line): self
    {
        return new self(sprintf('Line %d of %s is not a captured live update.', $line, $capture));
    }

    public static function noneOfRound(string $capture, string $round): self
    {
        return new self(sprintf('%s holds no live update of review round %s.', $capture, $round));
    }
}
