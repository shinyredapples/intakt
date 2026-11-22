<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use DateTimeImmutable;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Dashboard\Domain\JudgeCall;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewerProgress;
use Intakt\Core\Dashboard\Domain\ReviewOutcome;
use Intakt\Core\Dashboard\Domain\ReviewState;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundSource;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\CatchUpOwed;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\CountedQuotes;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\QuotesCounted;
use Intakt\Core\Review\Contract\ReviewerBlocked;
use Intakt\Core\Review\Contract\ReviewerFailed;
use Intakt\Core\Review\Contract\ReviewerFindingsRecorded;
use Intakt\Core\Review\Contract\ReviewerStarted;
use Intakt\Core\Review\Contract\ReviewerStopped;
use Intakt\Core\Review\Contract\ReviewStarted;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndingLookup;
use Intakt\Core\Review\Contract\RoundEndings;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictReceived;
use Intakt\Core\ReviewerForLoop\Contract\ProjectMode;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\Vendor\Contract\ProviderUnreachable;
use Intakt\Tests\Support\FixedRoundEndings;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\MaxOutputTokensException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Uid\Uuid;

#[CoversClass(LedgerRounds::class)]
#[CoversClass(RoundPayloads::class)]
#[CoversClass(LedgerPayloads::class)]
#[CoversClass(LedgerTokens::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(ReviewStarted::class)]
#[UsesClass(ReviewerStarted::class)]
#[UsesClass(VerdictReceived::class)]
#[UsesClass(ReviewerFailed::class)]
#[UsesClass(ReviewerBlocked::class)]
#[UsesClass(ReviewerStopped::class)]
#[UsesClass(CatchUpOwed::class)]
#[UsesClass(ReviewerFindingsRecorded::class)]
#[UsesClass(JudgeDone::class)]
#[UsesClass(FindingIds::class)]
#[UsesClass(DroppedFinding::class)]
#[UsesClass(CountedQuotes::class)]
#[UsesClass(QuotesCounted::class)]
#[UsesClass(JudgeDoneFinding::class)]
#[UsesClass(UsageStarted::class)]
#[UsesClass(UsageSettled::class)]
#[UsesClass(TokenUsageRow::class)]
#[UsesClass(RoundSource::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(ReviewOutcome::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(ProjectMode::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(QuorumPolicy::class)]
#[UsesClass(RoundEnding::class)]
final class LedgerRoundsTest extends TestCase
{
    private const string START = '{"round":"r1","project":"alpha","reviewers":2,"change":"feat/thing","number":1}';
    private ?RoundCosts $costs = null;
    private ?RoundEndings $endings = null;

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

        self::assertSame([ReviewerProgress::Stopped, Decision::Blocked, 1], [$outcome?->progress, $outcome?->decision, $outcome?->findings]);
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

    public function testAStartedReviewerCarriesTheMomentItStarted(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard"}', new DateTimeImmutable('2026-10-28T12:05:00+00:00')),
        );

        self::assertEquals(new DateTimeImmutable('2026-10-28T12:05:00+00:00'), $review?->rounds[0]->outcomes[0]->since);
    }

    public function testAReviewerStartedAgainCarriesTheMomentOfItsLatestStart(): void
    {
        $reviewer = '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard"}';
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, $reviewer, new DateTimeImmutable('2026-10-28T12:05:00+00:00')),
            $this->event(LedgerEventKind::ReviewerStopped, $reviewer, new DateTimeImmutable('2026-10-28T12:10:00+00:00')),
            $this->event(LedgerEventKind::ReviewerStarted, $reviewer, new DateTimeImmutable('2026-10-28T12:20:00+00:00')),
        );

        self::assertEquals(new DateTimeImmutable('2026-10-28T12:20:00+00:00'), $review?->rounds[0]->outcomes[0]->since);
    }

    public function testAReviewerStartedWithAModelKeepsThatModelAfterItsVerdict(): void
    {
        $reviewer = '"round":"r1","reviewer":"x","role":"correctness","tier":"standard"';
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, '{'.$reviewer.',"model":"standard-model"}'),
            $this->event(LedgerEventKind::VerdictReceived, '{'.$reviewer.',"decision":"approve","findings":0}'),
        );

        $outcome = $review?->rounds[0]->outcomes[0];

        self::assertSame([ReviewerProgress::Answered, 'standard-model'], [$outcome?->progress, $outcome?->model]);
    }

    public function testAReviewerStartRecordedBeforeTheModelWasKeptReadsWithNoModel(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard"}'),
        );

        $outcome = $review?->rounds[0]->outcomes[0];

        self::assertSame([ReviewerProgress::Running, null], [$outcome?->progress, $outcome?->model]);
    }

    public function testAReviewerStartedAgainOnAnotherModelShowsTheModelOfItsLatestStart(): void
    {
        $reviewer = '"round":"r1","reviewer":"x","role":"correctness","tier":"standard"';
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewerStarted, '{'.$reviewer.',"model":"first-model"}'),
            $this->event(LedgerEventKind::ReviewerStopped, '{'.$reviewer.'}'),
            $this->event(LedgerEventKind::ReviewerStarted, '{'.$reviewer.',"model":"second-model"}'),
        );

        self::assertSame('second-model', $review?->rounds[0]->outcomes[0]->model);
    }

    public function testAReviewerWithoutARecordedStartHasNoModel(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::VerdictReceived, '{"round":"r1","reviewer":"x","role":"correctness","tier":"standard","decision":"approve","findings":0}'),
        );

        self::assertNull($review?->rounds[0]->outcomes[0]->model);
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
        yield 'reviewer-for-loop' => [',"mode":"reviewer-for-loop"', ProjectMode::ReviewerForLoop];
        yield 'live' => [',"mode":"live"', ProjectMode::Live];
        yield 'the removed shadow mode' => [',"mode":"shadow"', ProjectMode::Live];
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
            $this->row(LedgerEventKind::VerdictReceived, new VerdictReceived('r1', 'x', 'correctness', Tier::Standard, Decision::Changes, 2, ProjectMode::ReviewerForLoop)),
        );

        $outcome = $review?->rounds[0]->outcomes[0];

        self::assertSame([ReviewerProgress::Answered, Decision::Changes, 2, ProjectMode::ReviewerForLoop], [$outcome?->progress, $outcome?->decision, $outcome?->findings, $outcome?->mode]);
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

    public function testAReviewerThatFailsOverPartwayThroughAReviewIsNamedByTheSubstitutesModel(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::ReviewerStarted, new ReviewerStarted('r1', 'x', 'correctness', Tier::Standard, 'standard-model')),
            $this->row(LedgerEventKind::VerdictReceived, new VerdictReceived('r1', 'x', 'correctness', Tier::Standard, Decision::Changes, 2, ProjectMode::Live)),
            $this->row(LedgerEventKind::CatchUpOwed, new CatchUpOwed('r1', 'x', 'correctness', Tier::Standard, Provider::OpenAi, 'openai-model')),
        );

        $outcome = $review?->rounds[0]->outcomes[0];

        self::assertSame([ReviewerProgress::Answered, 'openai', 'openai-model'], [$outcome?->progress, $outcome?->substitute, $outcome?->model]);
    }

    public function testACatchUpRecordedWithoutAModelKeepsTheModelOfTheStart(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::ReviewerStarted, new ReviewerStarted('r1', 'x', 'correctness', Tier::Standard, 'standard-model')),
            $this->row(LedgerEventKind::CatchUpOwed, new CatchUpOwed('r1', 'x', 'correctness', Tier::Standard, Provider::OpenAi)),
        );

        $outcome = $review?->rounds[0]->outcomes[0];

        self::assertSame(['openai', 'standard-model'], [$outcome?->substitute, $outcome?->model]);
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
            $this->row(LedgerEventKind::JudgeDone, new JudgeDone('r1', new FindingIds('F1'), new FindingIds('F2'), [new JudgeDoneFinding('F1', Severity::P1, 'Broken.'), new JudgeDoneFinding('F2', Severity::P3, 'Later.')])),
        );

        self::assertEquals([new RoundFinding('F1', 'P1', 'Broken.')->calledBy(JudgeCall::Act), new RoundFinding('F2', 'P3', 'Later.')->calledBy(JudgeCall::FollowUp)], $review?->rounds[0]->findings);
    }

    public function testAJudgedRoundReadsBackTheFindingsDroppedBeforeTheJudgeWithTheirReasons(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::JudgeDone, new JudgeDone('r1', new FindingIds(), new FindingIds(), [], [new DroppedFinding('F1', Severity::P2, 'Call `ghost`.', 'src/A.php', 3, 'none of its 1 quoted code fragments is in the change')])),
        );

        self::assertInstanceOf(Review::class, $review);
        self::assertEquals([new RoundFinding('F1', 'P2', 'Call `ghost`.', [], 'src/A.php', 3, 'none of its 1 quoted code fragments is in the change', new QuotesCounted('ghost'))], $review->rounds[0]->dropped);
        self::assertSame([], $review->rounds[0]->findings);
    }

    public function testAJudgeRowWrittenBeforeDropReasonsWereRecordedReadsWithNoDroppedFindings(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F1"],"follow_ups":[],"findings":[{"id":"F1","severity":"P1","text":"Broken."}]}'),
        );

        self::assertSame([], $review?->rounds[0]->dropped);
    }

    public function testAnUnreadableDroppedEntryIsLeftOutAndTheRoundStaysJudged(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":[],"follow_ups":[],"findings":[],"dropped":[{"id":"F1"},{"id":"F2","severity":"P2","text":"T.","file":null,"line":null,"reason":"why"}]}'),
        );

        self::assertTrue($review?->rounds[0]->isJudged());
        self::assertSame(['F2'], array_map(static fn (RoundFinding $finding): string => $finding->id, $review->rounds[0]->dropped));
    }

    public function testAJudgedFindingInTheReadModelNamesItsFileAndLine(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::JudgeDone, new JudgeDone('r1', new FindingIds('F1'), new FindingIds(), [new JudgeDoneFinding('F1', Severity::P1, 'Broken.', new FindingIds(), 'src/A.php', 7)])),
        );

        self::assertSame(['src/A.php', 7], [$review?->rounds[0]->findings[0]->file, $review?->rounds[0]->findings[0]->line]);
    }

    public function testTheJudgesCallReadsInPlainWordsWithTheDropReason(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::JudgeDone, new JudgeDone('r1', new FindingIds('F1'), new FindingIds('F2'), [new JudgeDoneFinding('F1', Severity::P1, 'A.'), new JudgeDoneFinding('F2', Severity::P3, 'B.'), new JudgeDoneFinding('F3', Severity::P3, 'C.')])),
        );

        self::assertSame(['Fix now', 'Dropped: deferred to a follow-up issue', 'Dropped: rejected or not marked by the judge'], array_map(static fn (RoundFinding $finding): string => $finding->callInWords(), $review?->rounds[0]->findings ?? []));
    }

    public function testAMarkedOnlyFindingFromAnOldRoundHasNoFileAndNoLine(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::JudgeDone, new JudgeDone('r1', new FindingIds('F1'), new FindingIds(), [])),
        );

        self::assertSame([null, null], [$review?->rounds[0]->findings[0]->file, $review?->rounds[0]->findings[0]->line]);
    }

    public function testAnActEntryThatMergesFindingsIsOneRowNamingTheMergedIds(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->row(LedgerEventKind::JudgeDone, new JudgeDone('r1', new FindingIds('F1', 'F2'), new FindingIds(), [new JudgeDoneFinding('F1', Severity::P1, 'Broken.', new FindingIds('F2')), new JudgeDoneFinding('F2', Severity::P1, 'Also broken.')])),
        );

        self::assertEquals([new RoundFinding('F1', 'P1', 'Broken.', ['F2'])->calledBy(JudgeCall::Act)], $review?->rounds[0]->findings);
        self::assertSame(['F1', 'F2'], $review?->rounds[0]->findings[0]->ids());
    }

    public function testAJudgeRowWrittenBeforeMergedIdsWereRecordedKeepsEveryFindingAsItsOwnRow(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":["F1","F2"],"follow_ups":[],"findings":[{"id":"F1","severity":"P1","text":"Broken."},{"id":"F2","severity":"P2","text":"Also broken."}]}'),
        );

        self::assertSame([['F1'], ['F2']], array_map(static fn (RoundFinding $finding): array => $finding->ids(), $review?->rounds[0]->findings ?? []));
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

    public function testAJudgedRoundIsJudgedAtTheTimeTheJudgesEventWasRecorded(): void
    {
        $review = $this->reviewOf(
            'r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START, new DateTimeImmutable('2026-10-28T12:00:00+00:00')),
            $this->event(LedgerEventKind::ReviewerStarted, '{"round":"r1","reviewer":"r1-a","role":"correctness","tier":"standard"}', new DateTimeImmutable('2026-10-28T12:05:00+00:00')),
            $this->event(LedgerEventKind::JudgeDone, '{"round":"r1","act":[],"follow_ups":[],"findings":[]}', new DateTimeImmutable('2026-10-28T12:41:00+00:00')),
            $this->event(LedgerEventKind::ReviewerStarted, '{"round":"r1","reviewer":"r1-b","role":"security","tier":"standard"}', new DateTimeImmutable('2026-10-28T12:50:00+00:00')),
        );

        self::assertEquals(new DateTimeImmutable('2026-10-28T12:41:00+00:00'), $review?->rounds[0]->judgedAt);
    }

    public function testARoundTheJudgeHasNotJudgedHasNoJudgedTime(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, self::START));

        self::assertNull($review?->rounds[0]->judgedAt);
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

    public function testAFailedReviewerIsNotedInPlainWordsWithoutItsClassName(): void
    {
        $review = $this->reviewOf('r1', $this->quorumStart(2, QuorumPolicy::All), $this->failed('x'));

        self::assertSame([ReviewerProgress::Failed, 'stopped at its output limit'], [$review?->rounds[0]->outcomes[0]->progress, $review?->rounds[0]->outcomes[0]->note]);
    }

    public function testAReviewerThatCouldNotReachItsEndpointIsNotedWithThatCauseInPlainWords(): void
    {
        $unreachable = $this->row(LedgerEventKind::ReviewerFailed, new ReviewerFailed('r1', 'x', 'security', Tier::Strong, ProviderUnreachable::class));

        $review = $this->reviewOf('r1', $this->quorumStart(2, QuorumPolicy::All), $unreachable);

        self::assertSame([ReviewerProgress::Failed, 'the model endpoint could not be reached'], [$review?->rounds[0]->outcomes[0]->progress, $review?->rounds[0]->outcomes[0]->note]);
    }

    public function testABlockedReviewerIsNotedWithItsReason(): void
    {
        $blocked = $this->row(LedgerEventKind::ReviewerBlocked, new ReviewerBlocked('r1', 'x', 'security', Tier::Strong, 'Every provider of the tier is out.'));

        $review = $this->reviewOf('r1', $this->quorumStart(2, QuorumPolicy::All), $blocked);

        self::assertSame([ReviewerProgress::Blocked, 'Every provider of the tier is out.'], [$review?->rounds[0]->outcomes[0]->progress, $review?->rounds[0]->outcomes[0]->note]);
    }

    public function testABlockedVerdictIntaktGaveIsNotedWithItsReason(): void
    {
        $blocked = $this->row(LedgerEventKind::VerdictReceived, new VerdictReceived('r1', 'x', 'security', Tier::Strong, Decision::Blocked, 1, ProjectMode::Live, 'The reviewer stream ended before its verdict.'));

        $review = $this->reviewOf('r1', $this->quorumStart(2, QuorumPolicy::All), $blocked);

        self::assertSame([ReviewOutcome::Blocked, 'The reviewer stream ended before its verdict.'], [$review?->rounds[0]->outcomes[0]->outcome(), $review?->rounds[0]->outcomes[0]->note]);
    }

    public function testABlockedVerdictRecordedBeforeReasonsWereKeptSaysItsReasonWasNotRecorded(): void
    {
        $older = $this->event(LedgerEventKind::VerdictReceived, '{"round":"r1","reviewer":"x","role":"security","tier":"strong","decision":"blocked","findings":1,"mode":"live"}');

        $review = $this->reviewOf('r1', $this->quorumStart(2, QuorumPolicy::All), $older);

        self::assertSame([ReviewOutcome::Blocked, 'Reason not recorded: this verdict was written before Intakt kept the reason of a blocked verdict.'], [$review?->rounds[0]->outcomes[0]->outcome(), $review?->rounds[0]->outcomes[0]->note]);
    }

    public function testAnAnsweredReviewerWithoutAReasonHasNoNote(): void
    {
        $review = $this->reviewOf('r1', $this->quorumStart(2, QuorumPolicy::All), $this->approved('x'));

        self::assertNull($review?->rounds[0]->outcomes[0]->note);
    }

    public function testAJudgedRoundWithAFailedReviewerBesideAnApprovalIsBlockedUnderTheAllPolicy(): void
    {
        $review = $this->reviewOf('r1', $this->quorumStart(2, QuorumPolicy::All), $this->approved('x'), $this->failed('y'), $this->judged());

        self::assertSame([Decision::Blocked, QuorumPolicy::All, 1], [$review?->rounds[0]->outcome, $review?->rounds[0]->quorum, $review?->rounds[0]->failedReviewers()]);
    }

    public function testAJudgedRoundWithAFailedReviewerBesideAChangesVerdictAsksForChanges(): void
    {
        $changes = $this->row(LedgerEventKind::VerdictReceived, new VerdictReceived('r1', 'x', 'correctness', Tier::Standard, Decision::Changes, 1, ProjectMode::Live));

        $review = $this->reviewOf('r1', $this->quorumStart(2, QuorumPolicy::All), $changes, $this->failed('y'), $this->judged());

        self::assertSame(Decision::Changes, $review?->rounds[0]->outcome);
    }

    public function testAChangesVerdictTheJudgeStepCountedAsApprovalApprovesTheRoundAsTheConsoleDoes(): void
    {
        $changes = $this->row(LedgerEventKind::VerdictReceived, new VerdictReceived('r1', 'x', 'correctness', Tier::Standard, Decision::Changes, 1, ProjectMode::Live));
        $judged = $this->row(LedgerEventKind::JudgeDone, new JudgeDone('r1', new FindingIds(), new FindingIds('F1'), [new JudgeDoneFinding('F1', Severity::P3, 'Later.')], votes: ['x' => Decision::Approve]));

        $review = $this->reviewOf('r1', $this->quorumStart(1, QuorumPolicy::All), $changes, $judged);

        self::assertSame([Decision::Approve, Decision::Changes], [$review?->rounds[0]->outcome, $review?->rounds[0]->outcomes[0]->decision]);
    }

    public function testAJudgeRowWithoutCountedVotesDecidesFromTheVerdictsAsGiven(): void
    {
        $changes = $this->row(LedgerEventKind::VerdictReceived, new VerdictReceived('r1', 'x', 'correctness', Tier::Standard, Decision::Changes, 1, ProjectMode::Live));

        $review = $this->reviewOf('r1', $this->quorumStart(1, QuorumPolicy::All), $changes, $this->judged());

        self::assertSame(Decision::Changes, $review?->rounds[0]->outcome);
    }

    public function testAFailedReviewerOutvotedByTwoApprovalsStillLetsTheMajorityPolicyApprove(): void
    {
        $review = $this->reviewOf('r1', $this->quorumStart(3, QuorumPolicy::Majority), $this->approved('x'), $this->approved('y'), $this->failed('z'), $this->judged());

        self::assertSame(Decision::Approve, $review?->rounds[0]->outcome);
    }

    public function testAReviewerThatNeverStartedVotesAsBlocked(): void
    {
        $review = $this->reviewOf('r1', $this->quorumStart(2, QuorumPolicy::All), $this->approved('x'), $this->judged());

        self::assertSame(Decision::Blocked, $review?->rounds[0]->outcome);
    }

    public function testAnAnswerFollowedByAFailureOfTheSameReviewerVotesAsBlocked(): void
    {
        $review = $this->reviewOf('r1', $this->quorumStart(1, QuorumPolicy::All), $this->approved('x'), $this->failed('x'), $this->judged());

        self::assertSame(Decision::Blocked, $review?->rounds[0]->outcome);
    }

    public function testEveryApprovalUnderTheAllPolicyApproves(): void
    {
        $review = $this->reviewOf('r1', $this->quorumStart(2, QuorumPolicy::All), $this->approved('x'), $this->approved('y'), $this->judged());

        self::assertSame([Decision::Approve, 0], [$review?->rounds[0]->outcome, $review?->rounds[0]->failedReviewers()]);
    }

    public function testARoundTheJudgeHasNotJudgedHasNoOutcome(): void
    {
        $review = $this->reviewOf('r1', $this->quorumStart(2, QuorumPolicy::All), $this->approved('x'), $this->failed('y'));

        self::assertNull($review?->rounds[0]->outcome);
    }

    public function testAJudgedRoundStartedBeforeTheQuorumWasRecordedHasNoOutcome(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, self::START), $this->approved('x'), $this->failed('y'), $this->judged());

        self::assertSame([null, null], [$review?->rounds[0]->quorum, $review?->rounds[0]->outcome]);
    }

    public function testAStartWithAQuorumOutsideTheKnownOnesIsSkipped(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, '{"round":"r1","project":"alpha","reviewers":2,"change":"feat/thing","number":1,"quorum":"anyone"}'));

        self::assertNull($review);
    }

    public function testARoundCarriesTheCostTheReviewModuleReadsForIt(): void
    {
        $this->costs = self::createStub(RoundCosts::class);
        $this->costs->method('costOf')->willReturn(Cost::inUsd(0.5));

        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, self::START));

        self::assertSame(0.5, $review?->rounds[0]->cost->usd);
    }

    public function testARoundTheJudgeHasNotJudgedTakesTheEndingTheReviewModuleNamesForIt(): void
    {
        $ending = new RoundEnding(RoundEnds::NoOutcomeInTime, 'no outcome in time');

        $this->endings = new FixedRoundEndings($ending);

        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, self::START));

        self::assertSame($ending, $review?->rounds[0]->ending);
    }

    public function testSeveralUnjudgedRoundsOfOneReadTakeOneLookupOfRoundEndings(): void
    {
        $lookup = self::createStub(RoundEndingLookup::class);
        $endings = $this->createMock(RoundEndings::class);
        $endings->expects(self::once())->method('lookup')->willReturn($lookup);
        $this->endings = $endings;

        $this->reviewOf('r1',
            $this->event(LedgerEventKind::ReviewStarted, self::START),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r2","project":"alpha","reviewers":2,"change":"feat/other","number":2}'),
            $this->event(LedgerEventKind::ReviewStarted, '{"round":"r3","project":"alpha","reviewers":2,"change":"feat/third","number":3}'),
        );
    }

    public function testAJudgedRoundGetsNoEndingEvenWhenTheReviewModuleNamesOne(): void
    {
        $ending = new RoundEnding(RoundEnds::NoOutcomeInTime, 'no outcome in time');

        $this->endings = new FixedRoundEndings($ending);

        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, self::START), $this->judged());

        self::assertNull($review?->rounds[0]->ending);
    }

    public function testTheFindingsEachReviewerReportedAreReadOntoTheirRound(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, self::START), $this->reported('x', ['F1', 'F2']), $this->reported('y', []), $this->judged());

        self::assertSame(['x' => ['F1', 'F2'], 'y' => []], $review?->rounds[0]->findingsByReviewer);
    }

    public function testARoundWhoseSecondReviewerHasNoReadableRecordReadsAsUnrecorded(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, self::START), $this->approved('x'), $this->approved('y'), $this->reported('x', ['F1']), $this->event(LedgerEventKind::ReviewerFindingsRecorded, '{"round":"r1","reviewer":"y","findings":[7]}'), $this->judged());

        self::assertNull($review?->rounds[0]->findingsByReviewer);
    }

    public function testARoundWhereEveryReviewerWithAVerdictHasARecordIsReadAsRecorded(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, self::START), $this->approved('x'), $this->approved('y'), $this->reported('x', ['F1']), $this->reported('y', []), $this->judged());

        self::assertSame(['x' => ['F1'], 'y' => []], $review?->rounds[0]->findingsByReviewer);
    }

    public function testARoundWithoutARecordOfWhoReportedWhatReadsAsUnrecordedNotAsEmpty(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, self::START), $this->approved('x'), $this->judged());

        self::assertNull($review?->rounds[0]->findingsByReviewer);
    }

    public function testARecordOfWhoReportedWhatThatNamesANonTextIdIsSkipped(): void
    {
        $review = $this->reviewOf('r1', $this->event(LedgerEventKind::ReviewStarted, self::START), $this->event(LedgerEventKind::ReviewerFindingsRecorded, '{"round":"r1","reviewer":"x","findings":[7]}'));

        self::assertNull($review?->rounds[0]->findingsByReviewer);
    }

    /**
     * @param list<string> $findings
     */
    private function reported(string $reviewer, array $findings): RecordedEvent
    {
        return $this->row(LedgerEventKind::ReviewerFindingsRecorded, new ReviewerFindingsRecorded('r1', $reviewer, $findings));
    }

    private function quorumStart(int $reviewers, QuorumPolicy $quorum): RecordedEvent
    {
        return $this->row(LedgerEventKind::ReviewStarted, new ReviewStarted('r1', 'alpha', $reviewers, 'feat/thing', 1, 'Add the thing.', quorum: $quorum));
    }

    private function approved(string $reviewer): RecordedEvent
    {
        return $this->row(LedgerEventKind::VerdictReceived, new VerdictReceived('r1', $reviewer, 'correctness', Tier::Standard, Decision::Approve, 0, ProjectMode::Live));
    }

    private function failed(string $reviewer): RecordedEvent
    {
        return $this->row(LedgerEventKind::ReviewerFailed, new ReviewerFailed('r1', $reviewer, 'security', Tier::Strong, MaxOutputTokensException::class));
    }

    private function judged(): RecordedEvent
    {
        return $this->row(LedgerEventKind::JudgeDone, new JudgeDone('r1', new FindingIds(), new FindingIds(), []));
    }

    private function event(LedgerEventKind $kind, string $payload, ?DateTimeImmutable $at = null): RecordedEvent
    {
        return new RecordedEvent(Uuid::v7(), new LedgerEvent($kind, $payload), $at ?? new DateTimeImmutable('2026-10-28T12:00:00+00:00'));
    }

    private function reviewOf(string $round, RecordedEvent ...$events): ?Review
    {
        $rounds = new LedgerRounds(new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), $this->costs ?? $this->noCosts(), new ChangeDecision(), new FailureInWords(), $this->endings ?? new FixedRoundEndings(), new ReplayMarks(new ReplayMarkBus()));

        foreach ($events as $event) {
            $rounds->add($event);
        }

        return $rounds->reviewOf($round);
    }

    private function noCosts(): RoundCosts
    {
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn(Cost::none());

        return $costs;
    }

    // Serialized as the writers do, so the reader is shown the row it meets in production.
    private function row(LedgerEventKind $kind, object $payload): RecordedEvent
    {
        return $this->event($kind, StoredSerializerFactory::create()->serialize($payload, JsonEncoder::FORMAT));
    }

    private function usage(int $prompt, int $completion): TokenUsageRow
    {
        return new TokenUsageRow('account', 'model-a', $prompt, $completion, null, null, null, null, null, $prompt + $completion, null, null, null);
    }
}
