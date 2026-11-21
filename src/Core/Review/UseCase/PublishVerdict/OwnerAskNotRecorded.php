<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use RuntimeException;

// Thrown after the forge write, so Messenger's retry posts nothing twice (the comment is found by its token) and appends the line it missed.
final class OwnerAskNotRecorded extends RuntimeException
{
    public static function of(string $head, string $reason): self
    {
        return new self(sprintf('The owner ask for head %s could not be recorded: %s', $head, $reason));
    }
}
