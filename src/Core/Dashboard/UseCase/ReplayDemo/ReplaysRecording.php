<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ReplayDemo;

use DateTimeImmutable;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
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
            $this->refuseRoundsTheLedgerHolds($recording);
            $this->appendAtTheRecordedPace($recording, $factor);
        } finally {
            $lock->release();
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

    private function appendAtTheRecordedPace(Recording $recording, float $factor): void
    {
        $previous = array_first($recording->events)?->recordedAt;

        foreach ($recording->events as $number => $event) {
            $this->clock->sleep($this->secondsBetween($previous, $event->recordedAt) / $factor);
            $this->append($event, $recording, $number);
            $previous = $event->recordedAt;
        }
    }

    private function append(RecordedLedgerEvent $event, Recording $recording, int $number): void
    {
        $result = $this->ledger->append(new LedgerEvent($event->kind, $this->inTheDemoProject($event->payload, $recording->project)));

        if (!$result->succeeded()) {
            throw ReplayStopped::at($event->kind, $number, count($recording->events), (string) $result->failure);
        }
    }

    // A row recorded before the one ahead of it follows at once, because the ledger keeps the recorded order.
    private function secondsBetween(?DateTimeImmutable $earlier, DateTimeImmutable $later): float
    {
        if (!$earlier instanceof DateTimeImmutable) {
            return 0.0;
        }

        $seconds = $later->getTimestamp() - $earlier->getTimestamp() + ((int) $later->format('u') - (int) $earlier->format('u')) / 1_000_000;

        return max(0.0, $seconds);
    }

    // Encoded again only when it names the recorded project, with the stored serializer's options, so every other row stays the text the live review stored.
    private function inTheDemoProject(string $payload, string $recorded): string
    {
        $row = json_decode($payload);

        if (!$row instanceof stdClass || $recorded !== ($row->project ?? null)) {
            return $payload;
        }

        $row->project = CreateDemoProjectCommand::NAME;

        return json_encode($row, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function roundOf(string $payload): ?string
    {
        $round = json_decode($payload, true)['round'] ?? null;

        return is_string($round) ? $round : null;
    }
}
