<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

enum CiRunState: string
{
    // Kept apart from Unfinished, because a newer run of the same origin replaces a run that never started, while a started run may still finish red.
    case NotStarted = 'not_started';
    case Unfinished = 'unfinished';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    // Kept apart from Failed, because a push cancels the run of the head it replaces and that says nothing about the head.
    case Cancelled = 'cancelled';
}
