<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\RecordDemo;

use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ReviewerFindingsRecorded;
use LogicException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;

// Derives the keys the Trends charts read (#2981) from what a recording made before them already holds, so the shipped demo fills both charts without a new model run (#3053). It refuses a recording whose content cannot tell them, rather than guess.
final readonly class RecordingWithTrendsKeys
{
    public function __construct(
        private SerializerInterface $serializer,
    ) {
    }

    // Rows of an earlier upgrade are dropped and derived again, because running it twice must give the same file.
    public function of(Recording $recording): Recording
    {
        $events = array_values(array_filter($recording->events, static fn (RecordedLedgerEvent $event): bool => LedgerEventKind::ReviewerFindingsRecorded !== $event->kind));
        $verdicts = array_values(array_filter($events, static fn (RecordedLedgerEvent $event): bool => LedgerEventKind::VerdictReceived === $event->kind));
        $judged = $this->judgementOfTheOneRound($events, $verdicts);
        $completing = end($verdicts);

        if (!$completing instanceof RecordedLedgerEvent) {
            throw new LogicException('The recording holds no verdict.');
        }

        $upgraded = [];
        foreach ($events as $event) {
            $upgraded[] = LedgerEventKind::JudgeDone === $event->kind ? $this->withFallback($event, $judged) : $event;

            // Right after the verdict that completes the round, because StoredFindings writes one row per answered reviewer in that verdict's transaction.
            if ($event === $completing) {
                array_push($upgraded, ...$this->reporterRows($verdicts, $judged, $completing));
            }
        }

        return new Recording($recording->reviewedAt, $recording->intaktVersion, $recording->models, $recording->project, $recording->change, $upgraded, $recording->updates);
    }

    // False only with a judge note present, because only the judge's own answer writes a note and the fallback for an unreadable answer marks findings without one.
    /**
     * @param array<string, mixed> $judged
     */
    private function withFallback(RecordedLedgerEvent $event, array $judged): RecordedLedgerEvent
    {
        $noted = array_filter($this->listOf($judged['findings'] ?? null), static fn (array $finding): bool => is_string($finding['judge_note'] ?? null));

        if ([] === $noted) {
            throw new LogicException('No finding of judge_done carries a judge note, so the recording cannot tell the judge answered.');
        }

        $judged['fallback'] = false;

        return new RecordedLedgerEvent($event->id, $event->kind, json_encode($judged, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), $event->recordedAt);
    }

    // Given to a reviewer only when one reviewer reported every finding, because judge_done keeps each finding's reported severity but not who reported it.
    /**
     * @param list<RecordedLedgerEvent> $verdicts
     * @param array<string, mixed>      $judged
     *
     * @return list<RecordedLedgerEvent>
     */
    private function reporterRows(array $verdicts, array $judged, RecordedLedgerEvent $completing): array
    {
        /** @var list<array{id: string, severity: string}> $reported */
        $reported = [...$this->listOf($judged['findings'] ?? null), ...$this->listOf($judged['dropped'] ?? null)];
        $severities = array_column($reported, 'severity', 'id');
        ksort($severities, SORT_NATURAL);

        /** @var list<array{round: string, reviewer: string, findings: int}> $decoded */
        $decoded = array_map(fn (RecordedLedgerEvent $verdict): array => $this->decoded($verdict->payload), $verdicts);
        $reporters = array_filter($decoded, static fn (array $verdict): bool => 0 < $verdict['findings']);

        if (1 < count($reporters) || array_sum(array_column($decoded, 'findings')) !== count($severities)) {
            throw new LogicException('More than one reviewer reported findings, or the judge_done row does not hold every reported finding, so the recording cannot tell who reported what.');
        }

        $rows = [];
        foreach ($decoded as $index => $verdict) {
            $own = 0 < $verdict['findings'] ? $severities : [];
            $payload = $this->serializer->serialize(new ReviewerFindingsRecorded($verdict['round'], $verdict['reviewer'], array_keys($own), $own), JsonEncoder::FORMAT);
            $rows[] = new RecordedLedgerEvent($this->idAfter($completing->id, $index + 1), LedgerEventKind::ReviewerFindingsRecorded, $payload, $completing->recordedAt);
        }

        return $rows;
    }

    // The completing verdict's UUIDv7 counted on, so the rows sort right after it in the recorded ledger order.
    private function idAfter(string $id, int $step): string
    {
        return substr($id, 0, -12).sprintf('%012x', hexdec(substr($id, -12)) + $step);
    }

    // One judgement and one round only, because the reporter rows and the fallback key are derived from that judgement alone (the shipped file holds one round).
    /**
     * @param list<RecordedLedgerEvent> $events
     * @param list<RecordedLedgerEvent> $verdicts
     *
     * @return array<string, mixed>
     */
    private function judgementOfTheOneRound(array $events, array $verdicts): array
    {
        $judgements = array_values(array_filter($events, static fn (RecordedLedgerEvent $event): bool => LedgerEventKind::JudgeDone === $event->kind));

        if (1 !== count($judgements)) {
            throw new LogicException(sprintf('The recording holds %d judge_done rows, not exactly one judge_done row, so the recording cannot tell which round each finding belongs to.', count($judgements)));
        }

        $judged = $this->decoded($judgements[0]->payload);
        foreach ($verdicts as $verdict) {
            if (($this->decoded($verdict->payload)['round'] ?? null) !== ($judged['round'] ?? null)) {
                throw new LogicException('A verdict_received row is from another round than its judge_done row, so the recording cannot tell which round each finding belongs to.');
            }
        }

        return $judged;
    }

    /**
     * @return array<string, mixed>
     */
    private function decoded(string $payload): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $entries): array
    {
        /** @var list<array<string, mixed>> $list */
        $list = is_array($entries) ? array_values($entries) : [];

        return $list;
    }
}
