<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

// The workflow configuration is built from these cases, so the names and the places have one home.
enum RoundTransition: string
{
    case Complete = 'complete';
    case Judge = 'judge';

    public function fromPlace(): RoundState
    {
        return match ($this) {
            self::Complete => RoundState::Collecting,
            self::Judge => RoundState::Complete,
        };
    }

    public function toPlace(): RoundState
    {
        return match ($this) {
            self::Complete => RoundState::Complete,
            self::Judge => RoundState::Judged,
        };
    }
}
