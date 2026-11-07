<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewerProgress;
use Intakt\Core\Dashboard\Domain\ReviewState;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundSource;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\CatchUpOwed;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\ReviewerBlocked;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\ReviewStarted;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LedgerRounds::class)]
#[CoversClass(RoundPayloads::class)]
#[CoversClass(LedgerPayloads::class)]
#[CoversClass(LedgerTokens::class)]
#[UsesClass(ReviewStarted::class)]
#[UsesClass(ReviewerStarted::class)]
#[UsesClass(VerdictReceived::class)]
#[UsesClass(ReviewerFailed::class)]
#[UsesClass(ReviewerBlocked::class)]
#[UsesClass(ReviewerStopped::class)]
#[UsesClass(CatchUpOwed::class)]
#[UsesClass(JudgeDone::class)]
#[UsesClass(JudgeDoneFinding::class)]
#[UsesClass(UsageStarted::class)]
#[UsesClass(UsageSettled::class)]
#[UsesClass(TokenUsageRow::class)]
#[UsesClass(RoundSource::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(ProjectMode::class)]
final class LedgerRoundsTest extends TestCase
{
    private const string START = '{"round":"r1","project":"alpha","reviewers":2,"change":"feat/thing","number":1}';

    public function testAStartWithANumberThatIsNoIntegerIsSkipped(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2,"change":"feat/thing","number":"one"}'));

        self::assertNull($review);
    }

    public function testARepeatedStartDoesNotReplaceTheFirstReadableOne(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"beta","reviewers":5,"change":"feat/other","number":3}'),
        );

        self::assertSame(['alpha', 'feat/thing', 1], [$review?->project, $review?->change, $review?->rounds[0]->number]);
    }

    public function testAReviewerEventWithoutARoleIsSkipped(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, '{"round":"r1","reviewer":"x","tier":"standard"}'),
        );

        self::assertSame([], $review?->rounds[0]->outcomes);
    }

    public function testAVerdictWithoutAFindingCountLeavesTheReviewerAsItWas(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard"}'),
            $this->event(LedgerEventKind::VerdictReceived, '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard","decision":"approve"}'),
        );

        self::assertSame(ReviewerProgress::Running, $review?->rounds[0]->outcomes[0]->progress);
    }

    public function testAReviewerWhoseLedgerShowsAStopIsStoppedWithItsBlockedVerdictKept(): void
    {
        $reviewer = '"round":"r1","reviewer":"x","role":"correctness","tier":"standard"';
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, '{'.$reviewer.'}'),
            $this->event(LedgerEventKind::VerdictReceived, '{'.$reviewer.',"decision":"blocked","findings":1}'),
            $this->event(LedgerEventKind::ReviewerStopped, '{'.$reviewer.'}'),
        );

        $outcome = $review?->rounds[0]->outcomes[0];

        self::assertSame([ReviewerProgress::Stopped, 'blocked', 1], [$outcome?->progress, $outcome?->decision, $outcome?->findings]);
    }

    public function testAReviewerStartedAgainAfterAStopRunsAnew(): void
    {
        $reviewer = '"round":"r1","reviewer":"x","role":"correctness","tier":"standard"';
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStopped, '{'.$reviewer.'}'),
            $this->event(LedgerEventKind::ReviewerStarted, '{'.$reviewer.'}'),
        );

        self::assertSame(ReviewerProgress::Running, $review?->rounds[0]->outcomes[0]->progress);
    }

    #[DataProvider('modes')]
    public function testAVerdictCarriesItsRecordedModeAndReadsAsOrdinaryWithoutOne(string $field, ProjectMode $expected): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::VerdictReceived, '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard","decision":"approve","findings":0'.$field.'}'),
        );

        self::assertSame($expected, $review?->rounds[0]->outcomes[0]->mode);
    }

    /**
     * @return iterable<string, array{string, ProjectMode}>
     */
    public static function modes(): iterable
    {
        yield 'shadow' => [',"mode":"shadow"', ProjectMode::Shadow];
        yield 'live' => [',"mode":"live"', ProjectMode::Live];
        yield 'recorded before the field existed' => ['', ProjectMode::Live];
        yield 'unknown value' => [',"mode":"other"', ProjectMode::Live];
        yield 'not a string' => [',"mode":3', ProjectMode::Live];
    }

    public function testAVerdictWithADecisionOutsideTheKnownOnesIsSkipped(): void
    {
        $reviewer = '"round":"r1","reviewer":"x","role":"correctness","tier":"standard"';
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, '{'.$reviewer.'}'),
            $this->event(LedgerEventKind::VerdictReceived, '{'.$reviewer.',"decision":"maybe","findings":0,"mode":"other"}'),
        );

        self::assertSame(ReviewerProgress::Running, $review?->rounds[0]->outcomes[0]->progress);
    }

    public function testAStartInTodaysFormatPlacesTheRoundWithItsSource(): void
    {
        $review = $this->reviewOf('r1', $this->row(LedgerEventKind::ReviewStarted, new ReviewStarted('r1', 'alpha', 2, 'feat/thing', 3, 'Add the thing.', '/repos/alpha', 'main')));

        self::assertEquals([3, new RoundSource('/repos/alpha', 'main', 'Add the thing.')], [$review?->rounds[0]->number, $review?->rounds[0]->source]);
    }

    public function testAStartWrittenBeforeRoundsWereNumberedHasNoNumber(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2}'));

        self::assertSame(['', null], [$review?->change, $review?->rounds[0]->number]);
    }

    public function testAStartWithANullNumberHasNoNumber(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2,"change":"feat/thing","number":null}'));

        self::assertSame(['feat/thing', null], [$review?->change, $review?->rounds[0]->number]);
    }

    #[DataProvider('reviewerRows')]
    public function testAReviewerRowInTodaysFormatMovesTheReviewer(LedgerEventKind $kind, object $payload, ReviewerProgress $expected): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::VerdictReceived, new VerdictReceived('r1', 'x', 'correctness', Tier::Standard, Decision::Changes, 2, ProjectMode::Live)),
            $this->row($kind, $payload),
        );

        self::assertSame($expected, $review?->rounds[0]->outcomes[0]->progress);
    }

    /**
     * @return iterable<string, array{LedgerEventKind, object, ReviewerProgress}>
     */
    public static function reviewerRows(): iterable
    {
        yield 'started' => [LedgerEventKind::ReviewerStarted, new ReviewerStarted('r1', 'x', 'correctness', Tier::Standard), ReviewerProgress::Running];
        yield 'failed' => [LedgerEventKind::ReviewerFailed, new ReviewerFailed('r1', 'x', 'correctness', Tier::Standard, 'Timed out.'), ReviewerProgress::Failed];
        yield 'blocked' => [LedgerEventKind::ReviewerBlocked, new ReviewerBlocked('r1', 'x', 'correctness', Tier::Standard, 'No provider left.'), ReviewerProgress::Blocked];
        yield 'stopped' => [LedgerEventKind::ReviewerStopped, new ReviewerStopped('r1', 'x', 'correctness', Tier::Standard), ReviewerProgress::Stopped];
    }

    public function testAVerdictInTodaysFormatAnswersWithItsDecisionCountAndMode(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::VerdictReceived, new VerdictReceived('r1', 'x', 'correctness', Tier::Standard, Decision::Changes, 2, ProjectMode::Shadow)),
        );

        $outcome = $review?->rounds[0]->outcomes[0];

        self::assertSame([ReviewerProgress::Answered, 'changes', 2, ProjectMode::Shadow], [$outcome?->progress, $outcome?->decision, $outcome?->findings, $outcome?->mode]);
    }

    public function testACatchUpInTodaysFormatNamesTheSubstituteProvider(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::CatchUpOwed, new CatchUpOwed('r1', 'x', 'correctness', Tier::Standard, Provider::OpenAi)),
        );

        self::assertSame('openai', $review?->rounds[0]->outcomes[0]->substitute);
    }

    public function testAReviewerRowWithATierOutsideTheKnownOnesIsSkipped(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, '{"round":"r1","reviewer":"x","role":"correctness","tier":"mighty"}'),
        );

        self::assertSame([], $review?->rounds[0]->outcomes);
    }

    public function testAJudgeRowInTodaysFormatShowsItsFindingsWithTheirCalls(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::JudgeDone, new JudgeDone('r1', ['F1'], ['F2'], [new JudgeDoneFinding('F1', Severity::P1, 'Broken.'), new JudgeDoneFinding('F2', Severity::P3, 'Later.')])),
        );

        self::assertEquals([new RoundFinding('F1', 'P1', 'Broken.')->calledBy(JudgeCall::Act), new RoundFinding('F2', 'P3', 'Later.')->calledBy(JudgeCall::FollowUp)], $review?->rounds[0]->findings);
    }

    public function testAnEventOfAnotherKindWithReviewerFieldsAddsNoReviewer(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::AccountCooledDown, '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard"}'),
        );

        self::assertSame([], $review?->rounds[0]->outcomes);
    }

    public function testAJudgedFindingWithoutTextIsSkippedAndTheOthersKeepTheirCall(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F2"],"follow_ups":[],"findings":[{"id":"F1","severity":"P1"},{"id":"F2","severity":"P1","text":"Kept."}]}'),
        );

        self::assertEquals([new RoundFinding('F2', 'P1', 'Kept.')->calledBy(JudgeCall::Act)], $review?->rounds[0]->findings);
    }

    public function testAJudgeRowWithAnUnreadableFindingStillMarksItsRoundJudged(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F1"],"follow_ups":[],"findings":[{"id":"F1","severity":"P9","text":"Odd."}]}'),
        );

        self::assertSame(ReviewState::Judged, $review?->rounds[0]->state);
    }

    public function testAJudgedFindingWhoseTextOverflowsIsSkippedAndTheOthersKeepTheirCall(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F2"],"follow_ups":[],"findings":[{"id":"F1","severity":"P1","text":1e400},{"id":"F2","severity":"P1","text":"Kept."}]}'),
        );

        self::assertEquals([ReviewState::Judged, [new RoundFinding('F2', 'P1', 'Kept.')->calledBy(JudgeCall::Act)]], [$review?->rounds[0]->state, $review?->rounds[0]->findings]);
    }

    public function testAJudgeRowWhoseMarksDoNotDeserializeIsSkipped(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":"F1","follow_ups":[],"findings":[]}'),
        );

        self::assertSame([ReviewState::InProgress, []], [$review?->rounds[0]->state, $review?->rounds[0]->findings]);
    }

    public function testAMarkedIdWhoseFindingTextIsUnreadableKeepsItsCall(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F1"],"follow_ups":[],"findings":[{"id":"F1","severity":"P1"}]}'),
        );

        self::assertEquals([new RoundFinding('F1', null, null)->calledBy(JudgeCall::Act)], $review?->rounds[0]->findings);
    }

    public function testAJudgeDoneWithoutFindingsLeavesTheFindingTextUnrecorded(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F1"],"follow_ups":[]}'),
        );

        self::assertFalse($review?->rounds[0]->findingTextRecorded);
    }

    public function testAJudgeDoneWithFindingsRecordsTheFindingText(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":[],"follow_ups":[],"findings":[]}'),
        );

        self::assertTrue($review?->rounds[0]->findingTextRecorded);
    }

    public function testASecondJudgeDoneOfARoundChangesNothing(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":[],"follow_ups":[],"findings":[]}'),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F1"],"follow_ups":[],"findings":[{"id":"F1","severity":"P1","text":"Late."}]}'),
        );

        self::assertSame([], $review?->rounds[0]->findings);
    }

    public function testTokensSettledBeforeTheirStartedRowStillCountForTheRound(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::UsageRecorded, '{"call":"c1","phase":"unsettled","failure":"Timeout","usages":[{"prompt":10,"total":12}]}'),
            $this->event(LedgerEventKind::UsageRecorded, '{"call":"c1","phase":"started","project":"alpha","tier":"standard","round":"r1"}'),
        );

        self::assertEquals(new TokenFigures(10, 0, 0, 0, 0), $review?->rounds[0]->tokens);
    }

    public function testUsageRowsInTodaysFormatCountForTheRoundTheirCallStartedIn(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::UsageRecorded, new UsageStarted('c1', 'started', 'alpha', Tier::Standard, 'r1')),
            $this->row(LedgerEventKind::UsageRecorded, new UsageSettled('c1', 'settled', [$this->usage(7, 3), $this->usage(1, 1)])),
            $this->row(LedgerEventKind::UsageRecorded, new UsageStarted('c2', 'started', 'alpha', Tier::Standard, 'r2')),
            $this->row(LedgerEventKind::UsageRecorded, new UsageSettled('c2', 'settled', [$this->usage(50, 50)])),
        );

        self::assertEquals(new TokenFigures(8, 0, 0, 0, 4), $review?->rounds[0]->tokens);
    }

    public function testAUsageRowWithACountThatIsNoNumberIsSkipped(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::UsageRecorded, '{"call":"c1","phase":"started","project":"alpha","tier":"standard","round":"r1"}'),
            $this->event(LedgerEventKind::UsageRecorded, '{"call":"c1","phase":"settled","usages":[{"prompt":10,"completion":"many","total":12}]}'),
        );

        self::assertEquals(TokenFigures::none(), $review?->rounds[0]->tokens);
    }

    private function reviewOf(string $round, RecordedEvent ...$events): ?Review
    {
        $rounds = new LedgerRounds(new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())));

        foreach ($events as $event) {
            $rounds->add($event);
        }

        return $rounds->reviewOf($round);
    }

    private function event(LedgerEventKind $kind, string $payload): RecordedEvent
    {
        return new RecordedEvent(Uuid::v7(), new LedgerEvent($kind, $payload), new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }

    // Serialized as the writers do, so the reader is shown the row it meets in production.
    private function row(LedgerEventKind $kind, object $payload): RecordedEvent
    {
        return $this->event($kind, StoredSerializerFactory::create()->serialize($payload, 'json'));
    }

    private function usage(int $prompt, int $completion): TokenUsageRow
    {
        return new TokenUsageRow('account', 'model-a', $prompt, $completion, null, null, null, null, null, $prompt + $completion, null, null, null);
    }
}
