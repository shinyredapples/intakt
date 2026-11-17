<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Infrastructure\ReplayedStart;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordedLedgerEvent;
use Intakt\Core\Dashboard\UseCase\RecordDemo\Recording;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use stdClass;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

final readonly class ReplaysRecording
{
    // One replay at a time, because two replays of one recording would both find its rounds missing from the ledger and append them twice.
    public const string LOCK = 'dashboard-demo-replay';

    public function __construct(
        private Ledger $ledger,
        private LiveUpdates $updates,
        private ClockInterface $clock,
        private LockFactory $locks,
    ) {
    }

    /**
     * @throws ReplayIsRefused
     * @throws ReplayStopped
     */
    public function replay(Recording $recording, float $factor): void
    {
        // Without a lease, because a replay at the recorded pace can outlast any lease and the lock is released when it ends.
        $lock = $this->locks->createLock(self::LOCK, ttl: null);

        if (!$lock->acquire()) {
            throw ReplayIsRefused::whileAnotherReplayRuns();
        }

        try {
            $this->refuseUpdatesWithoutTopic($recording);
            $this->refuseRoundsTheLedgerHolds($recording);
            $this->playAtTheRecordedPace($recording, $factor);
        } finally {
            $lock->release();
        }
    }

    // Checked before the first append, because a refusal partway through would leave rows whose round blocks any second attempt.
    private function refuseUpdatesWithoutTopic(Recording $recording): void
    {
        foreach ($recording->updates as $number => $update) {
            if ('' === trim($update->topic)) {
                throw ReplayIsRefused::becauseAnUpdateHasNoTopic($number);
            }
        }
    }

    // A round the ledger already holds would merge two runs into one review page, and a round stored live on this install would lose its replay label.
    private function refuseRoundsTheLedgerHolds(Recording $recording): void
    {
        $held = array_map(static fn (RecordedEvent $row): ?string => self::roundOf($row->event->payload), iterator_to_array($this->ledger->readOfKind(LedgerEventKind::ReviewStarted), false));

        foreach ($recording->events as $event) {
            $round = LedgerEventKind::ReviewStarted === $event->kind ? self::roundOf($event->payload) : null;

            if (null !== $round && in_array($round, $held, true)) {
                throw ReplayIsRefused::becauseTheLedgerHolds($round);
            }
        }
    }

    // Each step's moment counts from the replay's start, so the time an append takes is not added to the gaps that follow it.
    private function playAtTheRecordedPace(Recording $recording, float $factor): void
    {
        $steps = $this->timelineOf($recording);
        $start = $this->clock->now();
        $number = 0;

        foreach ($steps as $step) {
            $due = $this->secondsBetween($this->momentOf($steps[0]), $this->momentOf($step)) / $factor;
            $this->clock->sleep(max(0.0, $due - $this->secondsBetween($start, $this->clock->now())));

            if ($step instanceof CapturedUpdate) {
                $this->updates->publish(new LiveEvent($step->topic, $step->data));

                continue;
            }

            $this->append(new LedgerEvent($step->kind, $this->asReplayed($step, $recording->project, $factor)), $recording, $number++);
        }
    }

    // Merged rather than sorted, so each list keeps its recorded order; a row goes before an update of the same moment, because the page an update reloads must find that row.
    /**
     * @return list<RecordedLedgerEvent|CapturedUpdate>
     */
    private function timelineOf(Recording $recording): array
    {
        $events = $recording->events;
        $updates = $recording->updates;
        $steps = [];

        while ([] !== $events && [] !== $updates) {
            $steps[] = $events[0]->recordedAt <= $updates[0]->at ? array_shift($events) : array_shift($updates);
        }

        return [...$steps, ...$events, ...$updates];
    }

    private function append(LedgerEvent $event, Recording $recording, int $number): void
    {
        $result = $this->ledger->append($event);

        if (!$result->succeeded()) {
            throw ReplayStopped::at($recording, $number, (string) $result->failure);
        }
    }

    // A step recorded before the first one follows at once, because the ledger and the capture keep the recorded order.
    private function secondsBetween(DateTimeImmutable $earlier, DateTimeImmutable $later): float
    {
        $seconds = $later->getTimestamp() - $earlier->getTimestamp() + ((int) $later->format('u') - (int) $earlier->format('u')) / 1_000_000;

        return max(0.0, $seconds);
    }

    // Encoded again only when it names the recorded project, with the stored serializer's options, so every other row stays the text the live review stored; the start row also keeps the factor, because a review page loaded later has no other way to know it.
    private function asReplayed(RecordedLedgerEvent $event, string $recorded, float $factor): string
    {
        $row = json_decode($event->payload);

        if (!$row instanceof stdClass || $recorded !== ($row->project ?? null)) {
            return $event->payload;
        }

        $row->project = CreateDemoProjectCommand::NAME;

        if (LedgerEventKind::ReviewStarted === $event->kind) {
            $row->{ReplayedStart::KEY} = $factor;
        }

        return json_encode($row, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function momentOf(RecordedLedgerEvent|CapturedUpdate $step): DateTimeImmutable
    {
        return $step instanceof CapturedUpdate ? $step->at : $step->recordedAt;
    }

    private static function roundOf(string $payload): ?string
    {
        $row = json_decode($payload, true);
        $round = is_array($row) ? ($row['round'] ?? null) : null;

        return is_string($round) ? $round : null;
    }
}
