<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Contract;

// The label names of docs/design/05-architecture/04-labels.md that intake reads, spelled once.
final readonly class WorkLabels
{
    public const string ELIGIBILITY = 'intakt';
    public const string IDEA = 'idea';
    public const string PRIORITY_PREFIX = 'priority:';
}
