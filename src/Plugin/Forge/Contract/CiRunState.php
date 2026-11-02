<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

enum CiRunState: string
{
    case Unfinished = 'unfinished';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    // Kept apart from Failed, because a push cancels the run of the head it replaces and that says nothing about the head.
    case Cancelled = 'cancelled';
}
