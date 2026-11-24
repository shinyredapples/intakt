<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Domain;

use DateTimeImmutable;
use Intakt\Core\Dashboard\Domain\History;
use Intakt\Core\Dashboard\Domain\HistoryRow;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(History::class)]
#[UsesClass(HistoryRow::class)]
#[UsesClass(PayloadFields::class)]
final class HistoryTest extends TestCase
{
    public function testEventsRecordedInTheSameSecondKeepAStableNewestFirstOrder(): void
    {
        $history = History::of(new RecordedEvents([
            $this->recorded('first', $this->noon()),
            $this->recorded('second', $this->noon()),
            $this->recorded('third', $this->noon()),
            $this->recorded('oldest', new DateTimeImmutable('2026-10-28T11:00:00+00:00')),
        ]));

        self::assertSame(['third', 'second', 'first', 'oldest'], array_map(static fn (HistoryRow $row): string => $row->who, $history->rows));
    }

    public function testAnEventNamingOnlyItsRoundBelongsToTheProjectTheRoundStartNames(): void
    {
        $round = Uuid::v7()->toRfc4122();

        $history = History::of(new RecordedEvents([
            $this->event(LedgerEventKind::ReviewStarted, ['round' => $round, 'project' => 'alpha'], $this->noon()),
            $this->event(LedgerEventKind::ReviewerStarted, ['round' => $round, 'role' => 'correctness'], $this->noon()),
            $this->event(LedgerEventKind::ReviewerStarted, ['round' => Uuid::v7()->toRfc4122()], $this->noon()),
        ]));

        self::assertSame([null, 'alpha', 'alpha'], array_map(static fn (HistoryRow $row): ?string => $row->project, $history->rows));
    }

    private function noon(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-28T12:00:00+00:00');
    }

    private function recorded(string $who, DateTimeImmutable $at): RecordedEvent
    {
        return $this->event(LedgerEventKind::OwnerAsked, ['actor' => $who], $at);
    }

    /**
     * @param array<string, string> $payload
     */
    private function event(LedgerEventKind $kind, array $payload, DateTimeImmutable $at): RecordedEvent
    {
        return new RecordedEvent(Uuid::v7(), new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)), $at);
    }
}
