<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Symfony\Component\Uid\Uuid;

// One run of a task, named by an id every step row of the run carries, because two runs of the same task can overlap and the task page shows the steps of one run only.
final readonly class BuildRun
{
    public function __construct(
        public string $project,
        public int $task,
        public string $id,
    ) {
    }

    // A random id, because the id only keeps runs apart: which run started last is the order of their task rows in the ledger, never the order of the ids.
    public static function startedFor(BuildTaskRequest $request): self
    {
        return new self($request->project, $request->issue, Uuid::v4()->toRfc4122());
    }

    // Because the task's title and body and a parked question are untrusted text, the payload names the task by its number only; the tokens only for a step that used any, so every other row keeps its shape.
    public function stepPayload(EndedStep $ended): string
    {
        $tokens = $ended->tokens > 0 ? ['tokens' => $ended->tokens] : [];

        return json_encode([
            'project' => $this->project,
            'task' => (string) $this->task,
            'run' => $this->id,
            'step' => $ended->step->value,
            'outcome' => $ended->outcome->value,
            'failure' => $ended->failure,
            'stop_cause' => $ended->stopCause?->value,
            ...$tokens,
        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // The run too, as in its steps, so a reader can tell which of a task's runs parked it; never the question, because it stays in the row the readers redact.
    public function parkedPayload(): string
    {
        return json_encode(['project' => $this->project, 'task' => (string) $this->task, 'run' => $this->id], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    // Length-prefixed, so no project name can make the key of another project's task.
    public function startLockKey(): string
    {
        return 'build-run-start-'.hash('sha256', strlen($this->project).':'.$this->project.':'.$this->task);
    }
}
