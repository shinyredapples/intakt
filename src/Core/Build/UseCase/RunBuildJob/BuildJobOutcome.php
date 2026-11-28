<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildObservation;
use Intakt\Core\Build\Domain\BuildQuestion;
use Intakt\Core\Build\Domain\BuildStopCause;

// Untrusted, because the builder wrote both: the hand-back check reads the bundle before anything trusts it.
final readonly class BuildJobOutcome
{
    public function __construct(
        public BuildVerdict $verdict,
        public ?string $bundle = null,
        public ?string $pullRequestText = null,
        public string $log = '',
        public ?BuildQuestion $question = null,
        // Beside the verdict, because a stopped session still bundles what it committed and its hand-back goes through the same check as any cut session's.
        public ?BuildStopCause $stopCause = null,
        // Null when the runner refused the output, because nothing of it can be read then.
        public ?BuildObservation $observation = null,
    ) {
    }

    public function built(): bool
    {
        return BuildVerdict::Built === $this->verdict;
    }

    public function stoppedFor(?BuildStopCause $cause): self
    {
        return clone($this, ['stopCause' => $cause]);
    }

    public function observedAs(BuildObservation $observation): self
    {
        return clone($this, ['observation' => $observation]);
    }
}
