<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;

// The last step of each judged round in a replay's timeline, its row or its last recorded update, because the judged state rendered with today's templates must stay the last update of the round's topic (#3470).
final readonly class JudgedRoundEnds
{
    // Keyed by the index of the last step, because the replay publishes a judged state right after the step at that index.
    /**
     * @param list<RecordedLedgerEvent|CapturedUpdate> $steps
     *
     * @return array<int, list<string>>
     */
    public function of(array $steps): array
    {
        $judged = $this->judgedTopicsOf($steps);
        $last = [];

        foreach ($steps as $index => $step) {
            $round = $judged[$this->topicOf($step)] ?? null;

            if (null !== $round) {
                $last[$round] = $index;
            }
        }

        $ends = [];

        foreach ($last as $round => $index) {
            $ends[$index][] = $round;
        }

        return $ends;
    }

    // Keyed by topic, because a recorded update names its round only through its topic.
    /**
     * @param list<RecordedLedgerEvent|CapturedUpdate> $steps
     *
     * @return array<string, string>
     */
    private function judgedTopicsOf(array $steps): array
    {
        $judged = [];

        foreach ($steps as $step) {
            $round = $step instanceof RecordedLedgerEvent && LedgerEventKind::JudgeDone === $step->kind ? $this->roundOf($step->payload) : null;

            if (null !== $round) {
                $judged[MercureRoundUpdates::topicOf($round)] = $round;
            }
        }

        return $judged;
    }

    // A row's topic is its round's, so a row and an update of one round compare alike; an empty topic for a row that names no round.
    private function topicOf(RecordedLedgerEvent|CapturedUpdate $step): string
    {
        if ($step instanceof CapturedUpdate) {
            return $step->topic;
        }

        $round = $this->roundOf($step->payload);

        return null === $round ? '' : MercureRoundUpdates::topicOf($round);
    }

    private function roundOf(string $payload): ?string
    {
        $row = json_decode($payload, true);
        $round = is_array($row) ? ($row['round'] ?? null) : null;

        return is_string($round) ? $round : null;
    }
}
