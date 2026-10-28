<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Ledger;

use DateTimeImmutable;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use Intakt\Core\Ledger\UseCase\ReadEvents\ReadEventsCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

#[CoversClass(ReadEventsCommand::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
final class ReadEventsCommandTest extends TestCase
{
    public function testTheEventsArePrintedInTheOrderTheLedgerReturnsThem(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturn(new RecordedEvents([
            $this->recorded('019a2600-0000-7000-8000-000000000001', LedgerEventKind::ReviewStarted, '{"pr":1}'),
            $this->recorded('019a2600-0000-7000-8000-000000000002', LedgerEventKind::JudgeDone, '{}'),
        ]));

        $tester = $this->whenTheCommandRuns($ledger);

        self::assertSame(
            "2026-10-28T12:00:00Z 019a2600-0000-7000-8000-000000000001 review_started {\"pr\":1}\n"
            ."2026-10-28T12:00:00Z 019a2600-0000-7000-8000-000000000002 judge_done {}\n"
            ."2 events\n",
            $tester->getDisplay(),
        );
        self::assertSame(0, $tester->getStatusCode());
    }

    public function testAnEmptyLedgerPrintsOnlyTheCount(): void
    {
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readInOrder')->willReturn(new RecordedEvents([]));

        self::assertSame("0 events\n", $this->whenTheCommandRuns($ledger)->getDisplay());
    }

    private function recorded(string $id, LedgerEventKind $kind, string $payload): RecordedEvent
    {
        return new RecordedEvent(Uuid::fromString($id), new LedgerEvent($kind, $payload), new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }

    private function whenTheCommandRuns(Ledger $ledger): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ReadEventsCommand($ledger));
        $tester = new CommandTester($application->find('intakt:ledger:events'));
        $tester->execute([]);

        return $tester;
    }
}
