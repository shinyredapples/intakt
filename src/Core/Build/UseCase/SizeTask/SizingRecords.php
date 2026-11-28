<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Core\Build\Contract\ReadinessGap;
use Intakt\Core\Build\Contract\SizingRequest;
use Intakt\Core\Build\Contract\SizingVerdict;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;

final readonly class SizingRecords
{
    public function __construct(private Ledger $ledger)
    {
    }

    // Length-prefixed, so no project name can make the key of another project's task; the lock of a sizing and the subject of its record share it.
    public static function keyOf(string $project, int $task): string
    {
        return 'task-sizing-'.hash('sha256', strlen($project).':'.$project.':'.$task);
    }

    // A hash, because the record and the comment's marker name the text a verdict is of without holding it: the title and the body are untrusted.
    public static function textOf(SizingRequest $request): string
    {
        return hash('sha256', strlen($request->title).':'.$request->title.$request->body);
    }

    // Only the last one, because an older verdict on a text the task held before says nothing of what the later one parked or released; an invalid answer settles nothing, so it is passed over and the task is tried again.
    public function latestVerdictOf(SizingRequest $request): ?RecordedSizing
    {
        $latest = null;

        foreach ($this->ledger->readOfKindAbout(LedgerEventKind::TaskSized, self::keyOf($request->project, $request->task)) as $recorded) {
            $latest = RecordedSizing::in($recorded->event->payload) ?? $latest;
        }

        return $latest;
    }

    // The task by its number, a hash of its text and the verdict's closed fields only, because the title, the body and the model's reason are untrusted text; the run, because a later ready verdict takes back the question this run parked.
    /**
     * @throws TaskNotSized
     */
    public function record(SizingRequest $request, ?SizingVerdict $verdict, string $run): void
    {
        $payload = json_encode([
            'project' => $request->project,
            'task' => (string) $request->task,
            'outcome' => SizingOutcome::of($verdict)->value,
            'missing' => array_map(static fn (ReadinessGap $gap): string => $gap->value, $verdict->missing ?? []),
            'size' => $verdict?->size->value,
            'estimated_lines' => $verdict?->estimated_lines,
            'text' => self::textOf($request),
            'run' => $run,
        ], JSON_THROW_ON_ERROR);

        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::TaskSized, $payload, self::keyOf($request->project, $request->task)));

        if (!$appended->succeeded()) {
            throw TaskNotSized::notRecorded($request->project, $request->task, (string) $appended->failure);
        }
    }
}
