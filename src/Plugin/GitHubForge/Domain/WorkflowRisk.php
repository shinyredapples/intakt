<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

enum WorkflowRisk: string
{
    case Unreadable = 'unreadable';
    case Trigger = 'trigger';
    case Runner = 'runner';
    case Permissions = 'permissions';
    case Secret = 'secret';
}
