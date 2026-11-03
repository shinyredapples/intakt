<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Closure;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ReviewerConversation;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\StopPace;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\Rule\InvocationOrder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(ReviewerStopping::class)]
#[CoversClass(ReviewerConversation::class)]
#[UsesClass(StopPace::class)]
final class ReviewerStoppingTest extends TestCase
{
    private MockClock $clock;
    private UuidV7 $round;
    private UuidV7 $reviewer;
    private InMemoryLedger $ledger;
    private RecordedRoundUpdates $updates;
    private ?Closure $progress = null;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-03 12:00:00 UTC');
        $this->round = new UuidV7();
        $this->reviewer = new UuidV7();
        $this->updates = new RecordedRoundUpdates();
        $this->ledger = new InMemoryLedger(recordedAt: $this->clock->now());
    }

    // A conversation id of its own, because the dashboard names the reviewer by the id the ledger records, never by the conversation the worker holds.
    public function testAStopRequestNamingTheReviewerCancelsItsRunWhoseConversationHasAnotherId(): void
    {
        $this->given(LedgerEventKind::ReviewerStopRequested);
        $execution = $this->execution(self::once());

        $this->watchAndReportProgress($execution);

        self::assertSame([LedgerEventKind::ReviewerStopRequested, LedgerEventKind::ReviewerStopped], $this->ledger->kinds());
        self::assertSame(['round' => $this->round->toRfc4122(), 'reviewer' => $this->reviewer->toRfc4122()], $this->ledger->payloads()[1]);
        self::assertSame(['stopped '.$this->reviewer->toRfc4122()], $this->updates->seen);
    }

    // Pressed while the reviewer waited for a retry or before its model call began, so the request is older than the run it stops.
    public function testAStopRequestedThirtySecondsBeforeTheRunBeganStillCancelsItAtItsFirstProgress(): void
    {
        $this->ledger = new InMemoryLedger(recordedAt: $this->clock->now()->modify('-30 seconds'));
        $this->given(LedgerEventKind::ReviewerStopRequested);
        $execution = $this->execution(self::once());

        $this->watchAndReportProgress($execution);

        self::assertContains(LedgerEventKind::ReviewerStopped, $this->ledger->kinds());
    }

    public function testARequestAlreadyAnsweredByAStopNeverStopsALaterRun(): void
    {
        $this->given(LedgerEventKind::ReviewerStopRequested);
        $this->given(LedgerEventKind::ReviewerStopped);
        $execution = $this->execution(self::never());

        $this->watchAndReportProgress($execution);

        self::assertSame([], $this->updates->seen);
    }

    public function testARequestMadeAfterTheLastStopIsPendingAgain(): void
    {
        $this->given(LedgerEventKind::ReviewerStopRequested);
        $this->given(LedgerEventKind::ReviewerStopped);
        $this->given(LedgerEventKind::ReviewerStopRequested);
        $execution = $this->execution(self::once());

        $this->watchAndReportProgress($execution);

        self::assertSame(['stopped '.$this->reviewer->toRfc4122()], $this->updates->seen);
    }

    public function testAStopOfTheSameReviewerIdInAnotherRoundLeavesThisRunAlone(): void
    {
        $other = new LedgerEvent(LedgerEventKind::ReviewerStopRequested, json_encode(['round' => new UuidV7()->toRfc4122(), 'reviewer' => $this->reviewer->toRfc4122()], JSON_THROW_ON_ERROR));
        self::assertTrue($this->ledger->append($other)->succeeded());
        $execution = $this->execution(self::never());

        $this->watchAndReportProgress($execution);

        self::assertSame([], $this->updates->seen);
    }

    private function given(LedgerEventKind $kind): void
    {
        $payload = json_encode(['round' => $this->round->toRfc4122(), 'reviewer' => $this->reviewer->toRfc4122()], JSON_THROW_ON_ERROR);

        self::assertTrue($this->ledger->append(new LedgerEvent($kind, $payload))->succeeded());
    }

    // A mock, because whether the agent's execution is cancelled is the outcome under test.
    private function execution(InvocationOrder $cancels): ExecutionInterface
    {
        $execution = $this->createMock(ExecutionInterface::class);
        $execution->method('onProgress')->willReturnCallback(function (Closure $callback) use ($execution): ExecutionInterface {
            $this->progress = $callback;

            return $execution;
        });
        $execution->expects($cancels)->method('cancel');

        return $execution;
    }

    private function watchAndReportProgress(ExecutionInterface $execution): void
    {
        $conversation = new ReviewerConversation('You review.', new UuidV7(), $this->round, $this->reviewer);

        new ReviewerStopping($this->ledger, $this->updates, $this->clock, new NullLogger())->watching($execution, $conversation);
        self::assertInstanceOf(Closure::class, $this->progress);
        ($this->progress)();
    }
}
