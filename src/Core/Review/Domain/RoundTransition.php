<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

// The workflow configuration is built from these cases, so the names and the places have one home.
enum RoundTransition: string
{
    case Complete = 'complete';

    public function fromPlace(): RoundState
    {
        return RoundState::Collecting;
    }

    public function toPlace(): RoundState
    {
        return RoundState::Complete;
    }
}
