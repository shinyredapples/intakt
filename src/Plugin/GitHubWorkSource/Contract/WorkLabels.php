<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Contract;

// Spelled once, because the label families are fixed by docs/design/05-architecture/04-labels.md
final readonly class WorkLabels
{
    public const string IDEA = 'idea';
    public const string ELIGIBILITY = 'intakt';
    public const string PRIORITY_PREFIX = 'priority:';
}
