<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

enum NothingPicked: string
{
    case WorkerNotTakingWork = 'worker-not-taking-work';
    case ListingIncomplete = 'listing-incomplete';
    case TrustNotChecked = 'trust-not-checked';
    case NoTaskReady = 'no-task-ready';
}
