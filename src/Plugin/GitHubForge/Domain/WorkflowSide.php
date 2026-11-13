<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

enum WorkflowSide: string
{
    case Head = 'head';
    case Main = 'main';
    case Merged = 'merged';
}
