<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

enum CiRunState: string
{
    // Kept apart from Unfinished, because a run the forge queued but never started can be replaced, while a started one may still fail.
    case Queued = 'queued';
    case Unfinished = 'unfinished';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    // Kept apart from Failed, because a push cancels the run of the head it replaces and that says nothing about the head.
    case Cancelled = 'cancelled';
}
