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

    // A verdict on the same text stands, because sizing it again would spend tokens and park the task twice; an invalid answer settles nothing, so it is tried again, and an edited task is sized afresh.
    public function holdsAVerdictOn(SizingRequest $request): bool
    {
        $text = $this->textOf($request);

        foreach ($this->ledger->readOfKindAbout(LedgerEventKind::TaskSized, self::keyOf($request->project, $request->task)) as $recorded) {
            $payload = json_decode($recorded->event->payload, true);

            if (is_array($payload) && $text === ($payload['text'] ?? null) && SizingOutcome::Invalid->value !== ($payload['outcome'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    // The task by its number, a hash of its text and the verdict's closed fields only, because the title, the body and the model's reason are untrusted text.
    /**
     * @throws TaskNotSized
     */
    public function record(SizingRequest $request, SizingOutcome $outcome, ?SizingVerdict $verdict): void
    {
        $payload = json_encode([
            'project' => $request->project,
            'task' => (string) $request->task,
            'outcome' => $outcome->value,
            'missing' => array_map(static fn (ReadinessGap $gap): string => $gap->value, $verdict->missing ?? []),
            'size' => $verdict?->size->value,
            'estimated_lines' => $verdict?->estimated_lines,
            'text' => $this->textOf($request),
        ], JSON_THROW_ON_ERROR);

        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::TaskSized, $payload, self::keyOf($request->project, $request->task)));

        if (!$appended->succeeded()) {
            throw TaskNotSized::notRecorded($request->project, $request->task, (string) $appended->failure);
        }
    }

    private function textOf(SizingRequest $request): string
    {
        return hash('sha256', strlen($request->title).':'.$request->title.$request->body);
    }
}
