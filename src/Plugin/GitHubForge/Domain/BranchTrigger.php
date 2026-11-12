<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// The triggers that run a workflow with no more than the rights of the branch it comes from; any other one asks.
enum BranchTrigger: string
{
    case Push = 'push';
    case PullRequest = 'pull_request';
    case MergeGroup = 'merge_group';
    case WorkflowDispatch = 'workflow_dispatch';
    case WorkflowCall = 'workflow_call';
    case Schedule = 'schedule';
}
