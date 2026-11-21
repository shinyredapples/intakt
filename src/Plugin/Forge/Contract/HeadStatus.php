<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Named after GitHub's commit status states, because the loop reads Intakt's verdict from that status (issue #1251, D1).
enum HeadStatus: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Error = 'error';
    // Pending, because an approved protected head handed to Intakt waits for the owner: docs/architecture/handed-to-intakt.md
    case Pending = 'pending';
}
