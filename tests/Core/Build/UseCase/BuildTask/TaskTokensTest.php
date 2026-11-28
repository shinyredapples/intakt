<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use DateTimeImmutable;
use Intakt\Core\Build\UseCase\BuildTask\TaskTokens;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildLogEvents;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Ledger\Contract\RecordedEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(TaskTokens::class)]
#[UsesClass(BuildLogEvents::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(PayloadFields::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(RecordedEvents::class)]
final class TaskTokensTest extends TestCase
{
    public function testTheTokensOfEveryBuildJobRowOfTheTaskAreAdded(): void
    {
        self::assertSame(1100, $this->tokensOf('{"project":"alpha","task":"7","step":"build_job","tokens":700}', '{"project":"alpha","task":"7","step":"build_job","tokens":400}'));
    }

    public function testRowsOfAnotherTaskAnotherProjectOrAnotherStepAreLeftOut(): void
    {
        self::assertSame(5, $this->tokensOf(
            '{"project":"alpha","task":"7","step":"build_job","tokens":5}',
            '{"project":"alpha","task":"8","step":"build_job","tokens":700}',
            '{"project":"beta","task":"7","step":"build_job","tokens":700}',
            '{"project":"alpha","task":"7","step":"push","tokens":700}',
        ));
    }

    public function testARowWithoutTokensOrWithTokensThatAreNoNumberAddsNothing(): void
    {
        self::assertSame(0, $this->tokensOf('{"project":"alpha","task":"7","step":"build_job"}', '{"project":"alpha","task":"7","step":"build_job","tokens":"700"}', 'not json'));
    }

    public function testTokensThatAddUpPastTheLargestIntegerAreHeldThere(): void
    {
        self::assertSame(PHP_INT_MAX, $this->tokensOf('{"project":"alpha","task":"7","step":"build_job","tokens":'.PHP_INT_MAX.'}', '{"project":"alpha","task":"7","step":"build_job","tokens":1}'));
    }

    private function tokensOf(string ...$payloads): int
    {
        $events = array_map(static fn (string $payload): RecordedEvent => new RecordedEvent(Uuid::v7(), new LedgerEvent(LedgerEventKind::BuildStepEnded, $payload), new DateTimeImmutable('2026-01-01')), array_values($payloads));
        $ledger = self::createStub(Ledger::class);
        $ledger->method('readOfKind')->willReturn(new RecordedEvents($events));

        return new TaskTokens($ledger)->usedBy('alpha', 7);
    }
}
