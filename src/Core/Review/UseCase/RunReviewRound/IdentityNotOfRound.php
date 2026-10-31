<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use InvalidArgumentException;

final class IdentityNotOfRound extends InvalidArgumentException
{
    public static function forTaskOrPanel(): self
    {
        return new self('The verdict identity of a round must name the task and the panel the round reviews.');
    }
}
