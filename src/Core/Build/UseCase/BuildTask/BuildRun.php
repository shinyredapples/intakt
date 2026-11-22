<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;

// One run of a task, named by an id every step row of the run carries, because two runs of the same task can overlap and the task page shows the steps of one run only.
final readonly class BuildRun
{
    public function __construct(
        public string $project,
        public int $task,
        public string $id,
    ) {
    }

    public static function startedFor(BuildTaskRequest $request): self
    {
        return new self($request->project, $request->issue, bin2hex(random_bytes(16)));
    }

    // Because the task's title and body and a parked question are untrusted text, the payload names the task by its number only.
    public function stepPayload(BuildStep $step, BuildStepOutcome $outcome, ?string $failure): string
    {
        return json_encode([
            'project' => $this->project,
            'task' => (string) $this->task,
            'run' => $this->id,
            'step' => $step->value,
            'outcome' => $outcome->value,
            'failure' => $failure,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
