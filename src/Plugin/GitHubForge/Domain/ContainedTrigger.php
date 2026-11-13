<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

// These run the workflow of the pushed or scheduled commit itself; the others (pull_request_target, workflow_run, issue_comment and the like) run with the base repository's rights on events outsiders can cause.
enum ContainedTrigger: string
{
    case Push = 'push';
    case PullRequest = 'pull_request';
    case WorkflowDispatch = 'workflow_dispatch';
    case Schedule = 'schedule';
    case MergeGroup = 'merge_group';
}
