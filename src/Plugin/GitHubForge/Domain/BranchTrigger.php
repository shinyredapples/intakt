<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// Any other trigger asks, because it may run with the base repository's rights on code a pull request controls.
enum BranchTrigger: string
{
    case Push = 'push';
    case PullRequest = 'pull_request';
    case MergeGroup = 'merge_group';
    case WorkflowDispatch = 'workflow_dispatch';
    case WorkflowCall = 'workflow_call';
    case Schedule = 'schedule';
}
