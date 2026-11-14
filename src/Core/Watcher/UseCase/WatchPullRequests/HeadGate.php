<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\HeadDecision;

interface HeadGate
{
    public function admit(WatchedProject $project, HeadDecision $green): HeadDecision;
}
