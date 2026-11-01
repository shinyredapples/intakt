<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(RecordedEvents::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
final class RecordedEventsTest extends TestCase
{
    public function testTheEventsAreIteratedInTheOrderTheyWereGiven(): void
    {
        $first = $this->recorded('{"n":1}');
        $second = $this->recorded('{"n":2}');

        self::assertSame([$first, $second], iterator_to_array(new RecordedEvents([$first, $second])));
    }

    public function testNoEventsIterateAsNone(): void
    {
        self::assertSame([], iterator_to_array(new RecordedEvents([])));
    }

    private function recorded(string $payload): RecordedEvent
    {
        return new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::UsageRecorded, $payload), new DateTimeImmutable('2026-10-27T12:00:00+00:00'));
    }
}
