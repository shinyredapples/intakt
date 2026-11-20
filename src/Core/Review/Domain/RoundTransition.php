<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

// The transition constants of ReviewRoundStateMachine take their values from these cases, so the names have one home.
enum RoundTransition: string
{
    case Complete = 'complete';
    case Judge = 'judge';
}
