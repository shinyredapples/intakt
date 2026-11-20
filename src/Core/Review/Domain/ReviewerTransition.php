<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

// The constants of Infrastructure/ReviewerStateMachine take their values from these cases, so the names have one home.
enum ReviewerTransition: string
{
    case Start = 'start';
    case Receive = 'receive';
    case Fail = 'fail';
    case Requeue = 'requeue';
    case Block = 'block';
    case Interrupt = 'interrupt';
    case Resume = 'resume';
}
