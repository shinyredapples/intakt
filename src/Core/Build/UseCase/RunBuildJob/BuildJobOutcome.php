<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\BuildStopCause;
use NoDiscard;

// Untrusted, because the builder wrote both: the hand-back check reads the bundle before anything trusts it.
final readonly class BuildJobOutcome
{
    public function __construct(
        public BuildVerdict $verdict,
        public ?string $bundle = null,
        public ?string $pullRequestText = null,
        public string $log = '',
        public ?BuildQuestion $question = null,
        public ?BuildStopCause $stopCause = null,
    ) {
    }

    // The verdict stays what the output shows, because a stopped build hands back its commits like any session cut at its allowance.
    #[NoDiscard]
    public function stoppedBy(BuildStopCause $cause): self
    {
        return clone($this, ['stopCause' => $cause]);
    }

    public function built(): bool
    {
        return BuildVerdict::Built === $this->verdict;
    }
}
