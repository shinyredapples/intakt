<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\SizeTask;

use Intakt\Core\Build\Contract\ReadinessGap;
use Intakt\Core\Build\Contract\SizingRequest;

// Only the text's hash, the parking run and the missing items of a `task_sized` line, because that is all a later sizing of the task decides on: whether to size again, whose question to remove, and which comment to ensure.
final readonly class RecordedSizing
{
    /**
     * @param list<ReadinessGap> $missing
     */
    private function __construct(
        private string $text,
        private SizingOutcome $outcome,
        private ?string $run,
        private array $missing,
    ) {
    }

    // Null for an invalid answer, for a line that names no text and for a parked line without known missing items, because none of them settles anything about the task.
    public static function in(string $payload): ?self
    {
        $fields = json_decode($payload, true);

        if (!is_array($fields)) {
            return null;
        }

        $text = $fields['text'] ?? null;
        $outcome = is_string($fields['outcome'] ?? null) ? SizingOutcome::tryFrom($fields['outcome']) : null;
        $missing = self::gapsIn($fields['missing'] ?? null);
        $run = $fields['run'] ?? null;

        if (!is_string($text) || !self::settles($outcome, $missing)) {
            return null;
        }

        return new self($text, $outcome, is_string($run) ? $run : null, $missing);
    }

    public function isOf(SizingRequest $request): bool
    {
        return SizingRecords::textOf($request) === $this->text;
    }

    public function parkedBy(): ?string
    {
        return SizingOutcome::Parked === $this->outcome ? $this->run : null;
    }

    /**
     * @return list<ReadinessGap>
     */
    public function parkedFor(): array
    {
        return SizingOutcome::Parked === $this->outcome ? $this->missing : [];
    }

    // An empty list for anything that is not a list of known gaps, because a comment worded from a guess would ask the author for the wrong item.
    /**
     * @return list<ReadinessGap>
     */
    private static function gapsIn(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values)) {
            return [];
        }

        $gaps = [];

        foreach ($values as $value) {
            $gap = is_string($value) ? ReadinessGap::tryFrom($value) : null;

            if (!$gap instanceof ReadinessGap) {
                return [];
            }

            $gaps[] = $gap;
        }

        return $gaps;
    }

    /**
     * @param list<ReadinessGap> $missing
     *
     * @phpstan-assert-if-true SizingOutcome $outcome
     */
    private static function settles(?SizingOutcome $outcome, array $missing): bool
    {
        return SizingOutcome::Ready === $outcome || (SizingOutcome::Parked === $outcome && [] !== $missing);
    }
}
