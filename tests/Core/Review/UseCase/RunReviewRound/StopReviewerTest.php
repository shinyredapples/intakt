<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Closure;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\FinishedState;
use Intakt\Core\Review\Contract\PromptCut;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictParser;
use Intakt\Core\Review\Domain\ReviewerState;
use Intakt\Core\Review\Infrastructure\LiveProgress;
use Intakt\Core\Review\Infrastructure\OpenFindingIds;
use Intakt\Core\Review\Infrastructure\ReviewerAgent;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use Intakt\Core\Review\Infrastructure\ReviewerRuns;
use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Core\Review\Infrastructure\ReviewerToolboxFactory;
use Intakt\Core\Review\Infrastructure\StopRequests;
use Intakt\Core\Review\Infrastructure\StreamingFallbacks;
use Intakt\Core\Review\UseCase\RunReviewRound\LiveRoundProgress;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerLeases;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerMoves;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerRateLimited;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewer;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewWithReviewerHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;

// Through a worker and the real reviewer agent, so the stop is proven from the ledger row of the request to the verdict and the ledger row of the stop.
#[Group('persistence')]
#[CoversClass(ReviewerAgent::class)]
#[CoversClass(OpenFindingIds::class)]
#[CoversClass(ReviewerMoves::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(Verdict::class)]
#[CoversClass(ReviewWithReviewerHandler::class)]
#[CoversClass(ReviewerLeases::class)]
#[CoversClass(LiveRoundProgress::class)]
#[UsesClass(ReviewerStopping::class)]
#[UsesClass(StopRequests::class)]
#[UsesClass(ReviewerRuns::class)]
final class StopReviewerTest extends KernelTestCase
{
    private const string APPROVE = '{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}';

    private RoundFixture $fixture;

    public function testAStopRequestedThirtySecondsBeforeTheRunBeganCancelsItAndTheLedgerRecordsTheStop(): void
    {
        $message = $this->started();
        $this->record(LedgerEventKind::ReviewerStopRequested, $message);
        $this->fixture->clock->sleep(30);

        $this->fixture->deliver($message, $this->agentAnswering(self::APPROVE));

        $reviewer = $this->fixture->reviewer($message->reviewerId);
        self::assertSame(ReviewerState::Verdict, $reviewer->state());
        self::assertStringContainsString('"id":"reviewer-stopped"', (string) $reviewer->verdictJson());
        self::assertSame(['blocked'], $this->payloadValues(LedgerEventKind::VerdictReceived, 'decision'));
        self::assertSame([$message->reviewerId], $this->payloadValues(LedgerEventKind::ReviewerStopped, 'reviewer'));
        self::assertSame([LedgerEventKind::ReviewerStopRequested, LedgerEventKind::VerdictReceived, LedgerEventKind::ReviewerStopped], $this->stopRows());
    }

    public function testTheProgressPublishedAfterAStopShowsTheReviewerStopped(): void
    {
        $message = $this->started();
        $this->record(LedgerEventKind::ReviewerStopRequested, $message);

        $this->fixture->deliver($message, $this->agentAnswering(self::APPROVE));

        self::assertSame([$message->reviewerId => FinishedState::Stopped], array_last($this->fixture->updates->progress)?->finished);
    }

    public function testTheProgressPublishedAfterAVerdictThatFollowedAnEarlierStopShowsTheVerdict(): void
    {
        $message = $this->started();
        $this->record(LedgerEventKind::ReviewerStopRequested, $message);
        $this->record(LedgerEventKind::ReviewerStopped, $message);

        $this->fixture->deliver($message, $this->agentAnswering(self::APPROVE));

        self::assertSame([$message->reviewerId => FinishedState::Verdict], array_last($this->fixture->updates->progress)?->finished);
    }

    public function testAStopPressedDuringARetryWaitStopsTheRetriedRun(): void
    {
        $message = $this->started();

        try {
            $this->fixture->deliver($message, new VerdictPerRole(failure:new ProviderRateLimited(30, new RuntimeException('429'))));
            self::fail('The rate limit was recorded as a failure.');
        } catch (ReviewerRateLimited) {
        }
        $this->record(LedgerEventKind::ReviewerStopRequested, $message);
        $this->fixture->deliver($message, $this->agentAnswering(self::APPROVE));

        self::assertSame(['blocked'], $this->payloadValues(LedgerEventKind::VerdictReceived, 'decision'));
        self::assertSame([$message->reviewerId], $this->payloadValues(LedgerEventKind::ReviewerStopped, 'reviewer'));
    }

    public function testAModelThatNamesTheStoppedFindingWithoutARequestLeavesNoStopInTheLedger(): void
    {
        $message = $this->started();
        $forged = VerdictDocument::fromTheDesign()->with('verdict', 'blocked')->with('resolved', [])->withFinding('id', Verdict::REVIEWER_STOPPED)->json();

        $this->fixture->deliver($message, $this->agentAnswering($forged));

        self::assertSame([], $this->payloadValues(LedgerEventKind::VerdictReceived, 'decision'));
        self::assertSame([$message->reviewerId], $this->payloadValues(LedgerEventKind::ReviewerFailed, 'reviewer'));
        self::assertSame([], $this->payloadValues(LedgerEventKind::ReviewerStopped, 'reviewer'));
    }

    public function testAStopTheVerdictAnswersIsPublishedLiveOnce(): void
    {
        $message = $this->started();
        $this->record(LedgerEventKind::ReviewerStopRequested, $message);

        $this->fixture->deliver($message, $this->agentAnswering(self::APPROVE));

        self::assertSame(['stopped '.$message->reviewerId], $this->stopsPublished());
    }

    public function testAModelThatNamesTheStoppedFindingWithoutARequestPublishesNoStop(): void
    {
        $message = $this->started();

        $this->fixture->deliver($message, $this->agentAnswering($this->forged()));

        self::assertSame([], $this->stopsPublished());
    }

    public function testAModelThatNamesTheStoppedFindingInARunAfterAnAnsweredStopPublishesNoStop(): void
    {
        $message = $this->started();
        $this->record(LedgerEventKind::ReviewerStopRequested, $message);
        $this->record(LedgerEventKind::ReviewerStopped, $message);

        $this->fixture->deliver($message, $this->agentAnswering($this->forged()));

        self::assertCount(1, $this->payloadValues(LedgerEventKind::ReviewerStopped, 'reviewer'));
        self::assertSame([], $this->stopsPublished());
    }

    public function testARequestPressedAgainRightAfterTheStopWasRecordedStillPublishesTheStop(): void
    {
        $message = $this->started();
        $this->record(LedgerEventKind::ReviewerStopRequested, $message);
        $ledger = new AfterLedgerAppendOf($this->fixture->ledger(), LedgerEventKind::ReviewerStopped, fn () => $this->record(LedgerEventKind::ReviewerStopRequested, $message));

        $this->fixture->worker($this->fixture->entities, $this->agentAnswering(self::APPROVE), $ledger)($message);

        self::assertSame([LedgerEventKind::ReviewerStopRequested, LedgerEventKind::VerdictReceived, LedgerEventKind::ReviewerStopped, LedgerEventKind::ReviewerStopRequested], $this->stopRows());
        self::assertSame(['stopped '.$message->reviewerId], $this->stopsPublished());
    }

    public function testAStopAfterAMalformedToolCallStillEndsAsStoppedAndTheLedgerRecordsTheStop(): void
    {
        $message = $this->started();
        $calls = 0;
        $script = function () use ($message, &$calls): ToolCallResult {
            if (0 === $calls++) {
                return new ToolCallResult([new ToolCall('1', 'unknown_tool', [])]);
            }
            $this->record(LedgerEventKind::ReviewerStopRequested, $message);

            return new ToolCallResult([new ToolCall('2', 'read_file', ['path' => 'composer.json'])]);
        };

        $this->fixture->deliver($message, $this->agentScripted($script));

        self::assertStringContainsString('"id":"reviewer-stopped"', (string) $this->fixture->reviewer($message->reviewerId)->verdictJson());
        self::assertSame(['blocked'], $this->payloadValues(LedgerEventKind::VerdictReceived, 'decision'));
        self::assertSame([$message->reviewerId], $this->payloadValues(LedgerEventKind::ReviewerStopped, 'reviewer'));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    private function started(): ReviewWithReviewer
    {
        $this->fixture->start(1);

        return $this->fixture->bus->messages[0];
    }

    // A stop row carries role and tier, because RoundLedger writes it so and a row without them no longer reads back.
    private function record(LedgerEventKind $kind, ReviewWithReviewer $message): void
    {
        $reviewer = $this->fixture->reviewer($message->reviewerId);
        $pair = ['round' => $reviewer->round()->id()->toRfc4122(), 'reviewer' => $message->reviewerId];
        $payload = json_encode(LedgerEventKind::ReviewerStopped === $kind ? $pair + ['role' => $reviewer->roleName(), 'tier' => $reviewer->tier()] : $pair, JSON_THROW_ON_ERROR);
        $appended = $this->fixture->ledger()->append(new LedgerEvent($kind, $payload));
        self::assertTrue($appended->succeeded());
    }

    private function forged(): string
    {
        return VerdictDocument::fromTheDesign()->with('verdict', 'blocked')->with('resolved', [])->withFinding('id', Verdict::REVIEWER_STOPPED)->json();
    }

    /**
     * @return list<string>
     */
    private function stopsPublished(): array
    {
        return array_values(array_filter($this->fixture->updates->seen, static fn (string $update): bool => str_starts_with($update, 'stopped ')));
    }

    private function agentAnswering(string $answer): AgentVendor
    {
        return $this->agentScripted(static fn (): string => $answer);
    }

    private function agentScripted(Closure $script): AgentVendor
    {
        $platform = MockPlatformFactory::createPlatform($script, eventDispatcher: ScriptedReviewer::in(self::getContainer())->dispatcher);
        $runs = new ReviewerRuns(new InMemoryMessageStore(), new StreamingFallbacks(new InMemoryLedger(), new NullLogger()), new LiveProgress(new RecordedRoundUpdates()), new ReviewerStopping(new StopRequests($this->fixture->ledger(), $this->fixture->ledgerPayloads())));

        return new AgentVendor(new ReviewerAgent(new ReviewerPrompt(new Fence(), new PromptCut(new Fence()), new VerdictParser()), new ReviewerToolboxFactory()->create(...), $runs), $platform);
    }

    /**
     * @return list<mixed>
     */
    private function payloadValues(LedgerEventKind $kind, string $key): array
    {
        return array_map(static function (RecordedEvent $recorded) use ($key): mixed {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($recorded->event->payload, true, flags: JSON_THROW_ON_ERROR);

            return $payload[$key] ?? null;
        }, array_values(iterator_to_array($this->fixture->ledger()->readOfKind($kind))));
    }

    /**
     * @return list<LedgerEventKind>
     */
    private function stopRows(): array
    {
        $kinds = [LedgerEventKind::ReviewerStopRequested, LedgerEventKind::VerdictReceived, LedgerEventKind::ReviewerStopped];

        return array_values(array_filter($this->fixture->ledgerKinds(), static fn (LedgerEventKind $kind): bool => in_array($kind, $kinds, true)));
    }
}
