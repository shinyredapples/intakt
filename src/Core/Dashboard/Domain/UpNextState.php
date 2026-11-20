<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

enum UpNextState
{
    case Running;
    case Judging;
    case BetweenReviewers;
    case Waiting;

    public function isInProgress(): bool
    {
        return self::Waiting !== $this;
    }
}
