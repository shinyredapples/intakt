<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Plugin\GitHubForge\Contract\ListedIssue;

final readonly class BuildTaskOutcome
{
    // $last is the step that failed, or the final step when every step passed, because the run stops at the first failure.
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

    /**
     * @return list<BuildStep>
     */
    public function passedSteps(): array
    {
        $steps = BuildStep::cases();
        $upToLast = array_slice($steps, 0, (int) array_search($this->last, $steps, true) + 1);

        return $this->failed() ? array_slice($upToLast, 0, -1) : $upToLast;
    }
}
