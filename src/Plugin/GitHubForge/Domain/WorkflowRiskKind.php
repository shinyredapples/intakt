<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

enum WorkflowRiskKind: string
{
    case Unreadable = 'unreadable';
    case NotHostedRunner = 'not-hosted-runner';
    case WritableToken = 'writable-token';
    case Secret = 'secret';
    case Trigger = 'trigger';
}
