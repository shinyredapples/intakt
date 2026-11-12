<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Contract;

// Spelled once because both the rules and the store read them: docs/design/05-architecture/04-labels.md
final readonly class WorkLabels
{
    public const string ELIGIBILITY = 'intakt';
    public const string IDEA = 'idea';
    public const string PRIORITY_PREFIX = 'priority:';
}
