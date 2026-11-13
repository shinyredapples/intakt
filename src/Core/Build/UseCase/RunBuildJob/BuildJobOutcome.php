<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

// Untrusted, because the builder wrote both: the hand-back check reads the bundle before anything trusts it.
final readonly class BuildJobOutcome
{
    public function __construct(
        public BuildVerdict $verdict,
        public ?string $bundle = null,
        public ?string $pullRequestText = null,
        public string $log = '',
    ) {
    }

    public function built(): bool
    {
        return BuildVerdict::Built === $this->verdict;
    }
}
