<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;

final readonly class BuildTaskOutcome
{
    // $last is the step that failed, the build job of a parked task, or the final step when every step passed, because the run stops at the first failure or question.
    public function __construct(
        public BuildStep $last,
        public ?string $failure,
        public ?ListedIssue $task,
    ) {
    }

    public function failed(): bool
    {
        return null !== $this->failure;
    }

    // Without a failure before the final step, because a run stops early without one only when its build job asked a question that was stored.
    public function parked(): bool
    {
        return !$this->failed() && array_last(BuildStep::cases()) !== $this->last;
    }

    /**
     * @return list<BuildStep>
     */
    public function passedSteps(): array
    {
        $steps = BuildStep::cases();
        $upToLast = array_slice($steps, 0, (int) array_search($this->last, $steps, true) + 1);

        return $this->failed() || $this->parked() ? array_slice($upToLast, 0, -1) : $upToLast;
    }
}
