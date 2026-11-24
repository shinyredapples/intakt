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
            $this->recorded('first', '2026-10-28T12:00:00+00:00'),
            $this->recorded('second', '2026-10-28T12:00:00+00:00'),
            $this->recorded('third', '2026-10-28T12:00:00+00:00'),
            $this->recorded('oldest', '2026-10-28T11:00:00+00:00'),
        ]));

        self::assertSame(['third', 'second', 'first', 'oldest'], array_map(static fn (HistoryRow $row): ?string => $row->who, $history->rows));
    }

    public function testAProjectFilterKeepsThatProjectsRowsAndRowsWithoutAProject(): void
    {
        $history = History::of(new RecordedEvents([
            $this->recorded('a', '2026-10-28T12:00:00+00:00', 'alpha'),
            $this->recorded('b', '2026-10-28T12:00:00+00:00', 'beta'),
            $this->recorded('c', '2026-10-28T12:00:00+00:00'),
        ]));

        self::assertSame(['c', 'a'], array_map(static fn (HistoryRow $row): ?string => $row->who, $history->ofProject('alpha')->rows));
        self::assertCount(3, $history->ofProject(null)->rows);
        self::assertCount(3, $history->ofProject('')->rows);
    }

    private function recorded(string $who, string $at, ?string $project = null): RecordedEvent
    {
        $payload = json_encode(array_filter(['actor' => $who, 'project' => $project]), JSON_THROW_ON_ERROR);

        return new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::OwnerAsked, $payload), new DateTimeImmutable($at));
    }
}
