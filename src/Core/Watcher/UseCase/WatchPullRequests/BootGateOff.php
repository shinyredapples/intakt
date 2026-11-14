<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Intakt\Core\Watcher\Domain\HeadDecision;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

// The watcher's gate until the head's boot runs in an isolated job container (the job sandbox of plan task T58): booting a pull request head runs its code as the install's user, beside the install's home, keys and database, so no head is booted yet; BootGate takes its place then.
#[AsAlias(HeadGate::class)]
final readonly class BootGateOff implements HeadGate
{
    public function admit(WatchedProject $project, HeadDecision $green): HeadDecision
    {
        return $green;
    }
}
