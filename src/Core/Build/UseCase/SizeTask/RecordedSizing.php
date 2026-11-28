<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Core\Build\Contract\SizingRequest;

// Only the text's hash and the parking run of a `task_sized` line, because that is all a later sizing of the task decides on: whether to size again, and whose question to remove.
final readonly class RecordedSizing
{
    private function __construct(
        private string $text,
        private SizingOutcome $outcome,
        private ?string $run,
    ) {
    }

    // Null for an invalid answer and for a line that names no text, because neither settles anything about the task.
    public static function in(string $payload): ?self
    {
        $fields = json_decode($payload, true);
        $text = is_array($fields) ? ($fields['text'] ?? null) : null;
        $outcome = is_array($fields) && is_string($fields['outcome'] ?? null) ? SizingOutcome::tryFrom($fields['outcome']) : null;
        $run = is_array($fields) ? ($fields['run'] ?? null) : null;

        if (!is_string($text) || !in_array($outcome, [SizingOutcome::Ready, SizingOutcome::Parked], true)) {
            return null;
        }

        return new self($text, $outcome, is_string($run) ? $run : null);
    }

    public function isOf(SizingRequest $request): bool
    {
        return SizingRecords::textOf($request) === $this->text;
    }

    public function parkedBy(): ?string
    {
        return SizingOutcome::Parked === $this->outcome ? $this->run : null;
    }
}
