<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

// Declared in the order a build runs them, because the outcome derives the passed steps from that order.
enum BuildStep: string
{
    case Task = 'task';
    case Mirror = 'mirror';
    case Clone = 'clone';
    case BuildJob = 'build_job';
    case HandBackCheck = 'hand_back_check';
    case PrePushCheck = 'pre_push_check';
    case Push = 'push';
    case PullRequest = 'pull_request';
}
