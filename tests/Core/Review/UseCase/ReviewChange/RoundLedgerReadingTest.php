<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\DroppedFinding;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\ModelNames;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewerFigures;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageUnsettled;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Bridge\ClaudeCode\TokenUsageExtractor as ClaudeCodeUsage;
use Symfony\AI\Platform\Bridge\Generic\Completions\TokenUsageExtractor as CompletionsUsage;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\SerializerInterface;
use WeakReference;

#[Group('persistence')]
#[CoversClass(RoundLedgerReading::class)]
#[CoversClass(LedgerPayloads::class)]
#[UsesClass(RoundTokens::class)]
#[UsesClass(Cost::class)]
#[UsesClass(ConfiguredPrices::class)]
#[UsesClass(JudgeDone::class)]
#[UsesClass(JudgeDoneFinding::class)]
#[UsesClass(DroppedFinding::class)]
#[UsesClass(UsageStarted::class)]
#[UsesClass(UsageSettled::class)]
#[UsesClass(UsageUnsettled::class)]
#[UsesClass(TokenUsageRow::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(ReviewerFigures::class)]
#[UsesClass(ModelNames::class)]
final class RoundLedgerReadingTest extends KernelTestCase
{
    private const string ROUND = '0192a000-0000-7000-8000-000000000001';

    private const string OTHER_ROUND = '0192a000-0000-7000-8000-000000000002';

    private RoundFixture $fixture;

    private Ledger $ledger;

    public function testTheTokensOfACallRecordedTheWayUsageLedgerRecordsItAreCounted(): void
    {
        $this->recordCall('c1', self::ROUND, 'settled');

        self::assertSame([1, 100, 20, 120], $this->tokensOf(self::ROUND));
    }

    public function testAnAnthropicCallCountsItsCacheReadsAndWritesInThePromptAndSumsTheTotal(): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => self::ROUND]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'settled', 'usages' => [['account' => 'anthropic.default', 'prompt' => 4, 'cached' => 39500, 'cache_creation' => 1500, 'cache_read' => 38000, 'total' => null] + $this->usage()]]);

        $tokens = $this->reading()->tokensOf(self::ROUND);

        self::assertEquals(new RoundTokens(1, 39504, 38000, 1500, 0, 20, 39524), $tokens);
    }

    public function testTheTokensAnUnsettledCallSpentAreCounted(): void
    {
        $this->recordCall('c1', self::ROUND, 'unsettled');

        self::assertSame([1, 100, 20, 120], $this->tokensOf(self::ROUND));
    }

    public function testACallStartedWithoutARoundIdCountsForNoRound(): void
    {
        $this->recordCall('c1', null, 'settled');

        self::assertSame([0, 0, 0, 0], $this->tokensOf(self::ROUND));
    }

    public function testTwoRoundsOfOneProjectWhoseCallsInterleaveEachCountOnlyTheirOwn(): void
    {
        $this->append(LedgerEventKind::ReviewStarted, ['round' => self::ROUND, 'project' => 'alpha', 'reviewers' => 1]);
        $this->append(LedgerEventKind::ReviewStarted, ['round' => self::OTHER_ROUND, 'project' => 'alpha', 'reviewers' => 1]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'a1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => self::ROUND]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'b1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => self::OTHER_ROUND]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'b1', 'phase' => 'settled', 'usages' => [['account' => null, 'prompt' => 500, 'completion' => 50, 'total' => 550]]]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'a1', 'phase' => 'settled', 'usages' => [['account' => null, 'prompt' => 100, 'completion' => 20, 'total' => 120]]]);

        self::assertSame([1, 100, 20, 120], $this->tokensOf(self::ROUND));
        self::assertSame([1, 500, 50, 550], $this->tokensOf(self::OTHER_ROUND));
    }

    public function testAUsageRowThatIsNotJsonIsSkipped(): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{"call": "c1", "phase": "settled"'))->succeeded());
        $this->recordCall('c1', self::ROUND, 'settled');

        self::assertSame([1, 100, 20, 120], $this->tokensOf(self::ROUND));
    }

    public function testASettledRowWithATokenCountThatIsNotANumberIsSkipped(): void
    {
        $this->recordCall('c1', self::ROUND, 'settled');
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'settled', 'usages' => [['prompt' => 'many'] + $this->usage()]]);

        self::assertSame([1, 100, 20, 120], $this->tokensOf(self::ROUND));
    }

    public function testAStartedRowOfAnUnknownTierIsSkipped(): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'huge', 'round' => self::ROUND]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'settled', 'usages' => [$this->usage()]]);

        self::assertSame([0, 0, 0, 0], $this->tokensOf(self::ROUND));
    }

    public function testARoundsCostIsItsCallsPricedByTheirModel(): void
    {
        $this->recordCall('c1', self::ROUND, 'settled');
        $this->recordCall('c2', self::ROUND, 'unsettled');

        self::assertEqualsWithDelta(2 * (100 * 2.0 + 20 * 10.0) / 1_000_000, $this->reading()->costOf(self::ROUND)->usd, 1e-12);
    }

    public function testARoundWithACallOfAModelWithoutAPriceHasAnUnknownCostNamingTheModel(): void
    {
        $this->recordCall('c1', self::ROUND, 'settled');
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c2', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'light', 'round' => self::ROUND, 'model' => 'small']);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c2', 'phase' => 'settled', 'usages' => [$this->usage()]]);

        $cost = $this->reading()->costOf(self::ROUND);

        self::assertSame([null, ['small']], [$cost->usd, $cost->unpriced]);
    }

    public function testAClaudeCodeCallWhoseAnswerNamesNoModelIsPricedByTheModelItsTierResolvedTo(): void
    {
        $usage = new ClaudeCodeUsage()->extract(new InMemoryRawResult(['usage' => ['input_tokens' => 100, 'output_tokens' => 20]]));

        $this->recordAnswer('c1', 'big', $usage);

        self::assertEqualsWithDelta((100 * 2.0 + 20 * 10.0) / 1_000_000, $this->reading()->costOf(self::ROUND)->usd, 1e-12);
    }

    public function testAnApiCallAnsweredByADatedSnapshotIsPricedByTheModelItsTierNamed(): void
    {
        $usage = new CompletionsUsage()->extract(new InMemoryRawResult(['model' => 'big-2026-08-07', 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120]]));

        $this->recordAnswer('c1', 'big', $usage);

        self::assertEqualsWithDelta((100 * 2.0 + 20 * 10.0) / 1_000_000, $this->reading()->costOf(self::ROUND)->usd, 1e-12);
    }

    public function testACallStartedBeforeTheModelWasKeptHasAnUnknownCostSayingSo(): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => self::ROUND]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'settled', 'usages' => [$this->usage()]]);

        $cost = $this->reading()->costOf(self::ROUND);

        self::assertSame([null, [ConfiguredPrices::NO_MODEL]], [$cost->usd, $cost->unpriced]);
    }

    public function testAnUnsettledCallOfAModelWithoutAPriceThatRecordedNoUsageHasAnUnknownCostNamingTheModel(): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'strong', 'round' => self::ROUND, 'model' => 'unpriced']);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'unsettled', 'failure' => 'RuntimeException', 'usages' => []]);

        $cost = $this->reading()->costOf(self::ROUND);

        self::assertSame([null, ['unpriced']], [$cost->usd, $cost->unpriced]);
    }

    public function testAnUnsettledCallOfAPricedModelThatRecordedNoUsageCostsNothing(): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'strong', 'round' => self::ROUND, 'model' => 'big']);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'unsettled', 'failure' => 'RuntimeException', 'usages' => []]);

        self::assertSame(0.0, $this->reading()->costOf(self::ROUND)->usd);
    }

    public function testARoundWithACallWhoseUsageLacksInputTokensHasAnUnknownCostInsteadOfTheOutputOnlySum(): void
    {
        $this->recordCall('c1', self::ROUND, 'settled');
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c2', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => self::ROUND, 'model' => 'big']);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c2', 'phase' => 'settled', 'usages' => [['account' => 'anthropic.default', 'prompt' => null, 'completion' => 3000, 'total' => null] + $this->usage()]]);

        $cost = $this->reading()->costOf(self::ROUND);

        self::assertSame([null, [], 'unknown'], [$cost->usd, $cost->unpriced, $cost->reading()]);
    }

    public function testACallLackingInputTokensOfAModelWithoutAPriceStillNamesTheModel(): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'light', 'round' => self::ROUND, 'model' => 'small']);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'settled', 'usages' => [['prompt' => null] + $this->usage()]]);

        $cost = $this->reading()->costOf(self::ROUND);

        self::assertSame([null, ['small']], [$cost->usd, $cost->unpriced]);
    }

    public function testACallReportingZeroInputTokensIsStillPriced(): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => self::ROUND, 'model' => 'big']);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'settled', 'usages' => [['prompt' => 0] + $this->usage()]]);

        self::assertEqualsWithDelta(20 * 10.0 / 1_000_000, $this->reading()->costOf(self::ROUND)->usd, 1e-12);
    }

    public function testARoundWithoutCallsCostsNothing(): void
    {
        $this->recordCall('c1', self::OTHER_ROUND, 'settled');

        self::assertSame(0.0, $this->reading()->costOf(self::ROUND)->usd);
    }

    public function testTheModelsOfAReviewerAreTheModelsItsCallsInTheRoundRanOnEachNamedOnce(): void
    {
        foreach ([['c1', 'r1', self::ROUND, 'big'], ['c2', 'r1', self::ROUND, 'small'], ['c3', 'r1', self::ROUND, 'big'], ['c4', 'r2', self::ROUND, 'other'], ['c5', 'r1', self::OTHER_ROUND, 'later']] as [$call, $reviewer, $round, $model]) {
            $this->append(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => $round, 'model' => $model, 'reviewer' => $reviewer]);
        }

        self::assertSame(['big', 'small'], $this->modelsOf('r1'));
    }

    public function testAReviewerWhoseCallsStartedBeforeTheModelWasKeptNamesNoModel(): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => self::ROUND, 'reviewer' => 'r1']);

        self::assertSame([], $this->modelsOf('r1'));
    }

    public function testEachReviewerOfARoundGetsOnlyTheTokensOfItsOwnCalls(): void
    {
        $this->recordReviewerCall('r1', 100);
        $this->recordReviewerCall('r1', 50);
        $this->recordReviewerCall('r2', 7);
        $this->recordReviewerCall('r1', 1000, self::OTHER_ROUND);

        $figures = $this->reading()->figuresOfReviewers(self::ROUND, ['r1', 'r2']);

        self::assertSame([[2, 150], [1, 7]], [[$figures['r1']->tokens->calls, $figures['r1']->tokens->prompt], [$figures['r2']->tokens->calls, $figures['r2']->tokens->prompt]]);
    }

    public function testAReviewerThatAnsweredHasADurationFromItsStartToItsVerdict(): void
    {
        $this->recordReviewerRow(LedgerEventKind::ReviewerStarted, 'r1');
        $this->fixture->clock->sleep(3);
        $this->recordReviewerRow(LedgerEventKind::VerdictReceived, 'r1', ['decision' => 'approve', 'findings' => 0, 'mode' => 'live']);

        self::assertSame(3000, $this->millisecondsOf('r1'));
    }

    public function testAReviewerThatEndedBlockedHasADurationFromItsStartToItsBlockedRow(): void
    {
        $this->recordReviewerRow(LedgerEventKind::ReviewerStarted, 'r1');
        $this->fixture->clock->sleep(1);
        $this->recordReviewerRow(LedgerEventKind::ReviewerBlocked, 'r1', ['reason' => 'its message was moved to the failure transport']);

        self::assertSame(1000, $this->millisecondsOf('r1'));
    }

    public function testAReviewerThatFailedOrWasStoppedHasADurationToItsLastEnd(): void
    {
        $this->recordReviewerRow(LedgerEventKind::ReviewerStarted, 'r1');
        $this->fixture->clock->sleep(1);
        $this->recordReviewerRow(LedgerEventKind::ReviewerFailed, 'r1', ['failure' => 'quota']);
        $this->fixture->clock->sleep(1);
        $this->recordReviewerRow(LedgerEventKind::ReviewerStopped, 'r1');

        self::assertSame(2000, $this->millisecondsOf('r1'));
    }

    public function testAReviewerWithAStartAndNoEndRowHasNoDuration(): void
    {
        $this->recordReviewerRow(LedgerEventKind::ReviewerStarted, 'r1');
        $this->recordReviewerRow(LedgerEventKind::ReviewerBlocked, 'r2', ['reason' => 'blocked']);

        self::assertNull($this->millisecondsOf('r1'));
    }

    public function testAnEndRowOfAnotherRoundGivesTheReviewerNoDuration(): void
    {
        $this->recordReviewerRow(LedgerEventKind::ReviewerStarted, 'r1');
        $this->recordReviewerRow(LedgerEventKind::ReviewerBlocked, 'r1', ['round' => self::OTHER_ROUND, 'reason' => 'blocked']);

        self::assertNull($this->millisecondsOf('r1'));
    }

    public function testAReviewerTheLedgerNeverNamedHasNoTokensNoModelsAndNoDuration(): void
    {
        $figures = $this->reading()->figuresOfReviewers(self::ROUND, ['r1'])['r1'];

        self::assertSame([0, [], null], [$figures->tokens->calls, [...$figures->models], $figures->milliseconds]);
    }

    public function testReadingTheTokensOfARoundDoesNotRetainTheUsageOfOtherRounds(): void
    {
        foreach (['unrelated-one', 'unrelated-two', 'unrelated-three'] as $call) {
            $this->append(LedgerEventKind::UsageRecorded, ['call' => $call]);
        }

        $held = [];
        $reading = new RoundLedgerReading($this->ledger, new LedgerPayloads($this->unrelatedUsageSerializer($held)), new ConfiguredPrices([]));

        self::assertSame(0, $reading->tokensOf(self::ROUND)->calls);
    }

    public function testReadingTheFiguresOfARoundsReviewersReadsTheLedgerTwiceHoweverManyReviewersItHas(): void
    {
        foreach (['r1', 'r2', 'r3'] as $reviewer) {
            $this->recordReviewerRow(LedgerEventKind::ReviewerStarted, $reviewer);
            $this->recordReviewerCall($reviewer, 10);
            $this->recordReviewerRow(LedgerEventKind::ReviewerStopped, $reviewer);
        }
        $counting = new ReadCountingLedger($this->ledger);

        $this->reading($counting)->figuresOfReviewers(self::ROUND, ['r1', 'r2', 'r3']);

        self::assertSame(2, $counting->reads);
    }

    public function testTheActIdsOfAJudgeDoneRowInTodaysFormatAreRead(): void
    {
        $this->append(LedgerEventKind::JudgeDone, ['round' => self::OTHER_ROUND, 'act' => ['F9'], 'follow_ups' => [], 'findings' => []]);
        $this->append(LedgerEventKind::JudgeDone, $this->judgeDone(self::ROUND));

        self::assertSame(['F1', 'F3'], $this->actIdsOf(self::ROUND));
    }

    public function testAJudgeDoneRowThatIsNotJsonIsSkipped(): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round": "'.self::ROUND.'", "act": ["F2"]'))->succeeded());
        $this->append(LedgerEventKind::JudgeDone, $this->judgeDone(self::ROUND));

        self::assertSame(['F1', 'F3'], $this->actIdsOf(self::ROUND));
    }

    public function testAJudgeDoneRowWithAFindingOfAnUnknownSeverityIsSkipped(): void
    {
        $this->append(LedgerEventKind::JudgeDone, ['act' => ['F2'], 'findings' => [['id' => 'F2', 'severity' => 'P9', 'text' => 'Odd.']]] + $this->judgeDone(self::ROUND));

        self::assertSame([], $this->actIdsOf(self::ROUND));
    }

    public function testTheDroppedFindingsOfAJudgeDoneRowAreReadWithTheirReasons(): void
    {
        $this->append(LedgerEventKind::JudgeDone, ['dropped' => [$this->dropped('F4')]] + $this->judgeDone(self::ROUND));

        self::assertEquals([new DroppedFinding('F4', Severity::P1, '`gone` is not defined.', 'src/A.php', 3, 'none of its 1 quoted code fragments is in the change')], $this->reading()->droppedOf(self::ROUND));
    }

    public function testAJudgeDoneRowWrittenBeforeDropReasonsWereRecordedReadsAsNoDroppedFindingsButKeepsItsActIds(): void
    {
        $this->append(LedgerEventKind::JudgeDone, $this->judgeDone(self::ROUND));

        self::assertSame([], $this->reading()->droppedOf(self::ROUND));
        self::assertSame(['F1', 'F3'], $this->actIdsOf(self::ROUND));
    }

    public function testAReviewersVoteIsReadAsTheJudgeStepCountedIt(): void
    {
        $this->append(LedgerEventKind::JudgeDone, ['votes' => ['reviewer-a' => 'approve']] + $this->judgeDone(self::ROUND));

        self::assertSame(['reviewer-a' => Decision::Approve], $this->reading()->votesOf(self::ROUND));
    }

    public function testAJudgeDoneRowWrittenBeforeVotesWereCountedReadsAsNoVotes(): void
    {
        $this->append(LedgerEventKind::JudgeDone, $this->judgeDone(self::ROUND));

        self::assertSame([], $this->reading()->votesOf(self::ROUND));
    }

    public function testTheVotesOfAnotherRoundAreNotRead(): void
    {
        $this->append(LedgerEventKind::JudgeDone, ['votes' => ['reviewer-a' => 'approve']] + $this->judgeDone('another-round'));

        self::assertSame([], $this->reading()->votesOf(self::ROUND));
    }

    public function testTheDroppedFindingsOfAnotherRoundAreNotRead(): void
    {
        $this->append(LedgerEventKind::JudgeDone, ['dropped' => [$this->dropped('F4')]] + $this->judgeDone(self::OTHER_ROUND));
        $this->append(LedgerEventKind::JudgeDone, $this->judgeDone(self::ROUND));

        self::assertSame([], $this->reading()->droppedOf(self::ROUND));
    }

    public function testTheJudgesSameCauseLinksAreReadFromTheRoundsJudgeDoneRow(): void
    {
        $this->append(LedgerEventKind::JudgeDone, ['same_cause' => ['F1' => 'F2']] + $this->judgeDone('another-round'));
        $this->append(LedgerEventKind::JudgeDone, ['same_cause' => ['F3' => 'F1']] + $this->judgeDone(self::ROUND));

        self::assertSame(['F3' => 'F1'], iterator_to_array($this->reading()->judgeDoneOf(self::ROUND)->sameCause ?? []));
    }

    public function testAJudgeDoneRowWrittenBeforeCausesWereLinkedReadsAsNoLinks(): void
    {
        $this->append(LedgerEventKind::JudgeDone, $this->judgeDone(self::ROUND));

        self::assertSame([], iterator_to_array($this->reading()->judgeDoneOf(self::ROUND)->sameCause ?? ['missing' => 'row']));
    }

    public function testTheJudgesNotesAreReadFromTheRoundsJudgeDoneRow(): void
    {
        $findings = [['id' => 'F1', 'severity' => 'P1', 'text' => 'Broken.', 'judge_note' => 'The branch is unreachable.'], ['id' => 'F2', 'severity' => 'P3', 'text' => 'Later.'], ['id' => 'F3', 'severity' => 'P2', 'text' => 'Untested.', 'judge_note' => null]];
        $this->append(LedgerEventKind::JudgeDone, ['findings' => $findings] + $this->judgeDone(self::ROUND));

        $findings = $this->reading()->judgeDoneOf(self::ROUND)->findings ?? [];

        self::assertSame(['The branch is unreachable.', null, null], array_map(static fn (JudgeDoneFinding $finding): ?string => $finding->judgeNote, $findings));
    }

    public function testARoundTheJudgeHasNotAnsweredHasNoJudgeDone(): void
    {
        $this->append(LedgerEventKind::JudgeDone, $this->judgeDone(self::OTHER_ROUND));

        self::assertNull($this->reading()->judgeDoneOf(self::ROUND));
    }

    public function testARoundTheJudgeHasNotAnsweredHasNoDroppedFindings(): void
    {
        self::assertSame([], $this->reading()->droppedOf(self::ROUND));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->ledger = $this->fixture->ledger();
    }

    /**
     * @param array<string, mixed> $more
     */
    private function recordReviewerRow(LedgerEventKind $kind, string $reviewer, array $more = []): void
    {
        $this->append($kind, $more + ['round' => self::ROUND, 'reviewer' => $reviewer, 'role' => 'correctness', 'tier' => 'standard']);
    }

    private function recordReviewerCall(string $reviewer, int $prompt, string $round = self::ROUND): void
    {
        $call = $round.'/'.$reviewer.'/'.$prompt;
        $this->append(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => $round, 'model' => 'big', 'reviewer' => $reviewer]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'settled', 'usages' => [['prompt' => $prompt] + $this->usage()]]);
    }

    /**
     * @return list<string>
     */
    private function modelsOf(string $reviewer): array
    {
        return [...$this->reading()->figuresOfReviewers(self::ROUND, [$reviewer])[$reviewer]->models];
    }

    private function millisecondsOf(string $reviewer): ?int
    {
        return $this->reading()->figuresOfReviewers(self::ROUND, [$reviewer])[$reviewer]->milliseconds;
    }

    /**
     * @param list<WeakReference<TokenUsageRow>> $held
     */
    private function unrelatedUsageSerializer(array &$held): SerializerInterface
    {
        $serializer = self::createStub(SerializerInterface::class);
        $serializer->method('deserialize')->willReturnCallback(
            static function (string $json, string $class) use (&$held): ?UsageSettled {
                if (UsageSettled::class !== $class) {
                    return null;
                }

                $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
                $call = is_array($decoded) && is_string($decoded['call'] ?? null) ? $decoded['call'] : '';
                self::assertFalse('unrelated-three' === $call && ($held[0] ?? null)?->get() instanceof TokenUsageRow, 'The usage of an unrelated call is still retained.');

                $usage = new TokenUsageRow(null, null, 42, null, null, null, null, null, null, null, null, null, null);
                $held[0] ??= WeakReference::create($usage);

                return new UsageSettled($call, 'settled', [$usage]);
            },
        );

        return $serializer;
    }

    /**
     * @return array<string, mixed>
     */
    private function dropped(string $id): array
    {
        return ['id' => $id, 'severity' => 'P1', 'text' => '`gone` is not defined.', 'file' => 'src/A.php', 'line' => 3, 'reason' => 'none of its 1 quoted code fragments is in the change'];
    }

    /**
     * @return array<string, mixed>
     */
    private function judgeDone(string $round): array
    {
        return ['round' => $round, 'act' => ['F1', 'F3'], 'follow_ups' => ['F2'], 'findings' => [['id' => 'F1', 'severity' => 'P1', 'text' => 'Broken.'], ['id' => 'F2', 'severity' => 'P3', 'text' => 'Later.'], ['id' => 'F3', 'severity' => 'P2', 'text' => 'Untested.']]];
    }

    /**
     * @return list<string>
     */
    private function actIdsOf(string $round): array
    {
        return [...$this->reading()->judgeDoneOf($round)->act ?? []];
    }

    private function recordCall(string $call, ?string $round, string $ending): void
    {
        $started = ['call' => $call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'model' => 'big'];
        $this->append(LedgerEventKind::UsageRecorded, null === $round ? $started : $started + ['round' => $round]);
        $ended = ['call' => $call, 'phase' => $ending, 'usages' => [$this->usage()]];
        $this->append(LedgerEventKind::UsageRecorded, 'unsettled' === $ending ? $ended + ['failure' => 'RuntimeException'] : $ended);
    }

    // Written through the payload classes from what the vendor's extractor read, because a row written by hand could carry a model the vendor never reports.
    private function recordAnswer(string $call, string $tierModel, ?TokenUsageInterface $usage): void
    {
        self::assertNotNull($usage);
        $row = new TokenUsageRow('claude_code.default', $usage->getModel(), $usage->getPromptTokens(), $usage->getCompletionTokens(), $usage->getThinkingTokens(), $usage->getToolTokens(), $usage->getCachedTokens(), $usage->getCacheCreationTokens(), $usage->getCacheReadTokens(), $usage->getTotalTokens(), $usage->getRemainingTokens(), $usage->getRemainingTokensMinute(), $usage->getRemainingTokensMonth());
        $serializer = StoredSerializerFactory::create();

        foreach ([new UsageStarted($call, 'started', 'alpha', Tier::Standard, self::ROUND, $tierModel), new UsageSettled($call, 'settled', [$row])] as $payload) {
            self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, $serializer->serialize($payload, JsonEncoder::FORMAT)))->succeeded());
        }
    }

    /**
     * @return list<int>
     */
    private function tokensOf(string $round): array
    {
        $tokens = $this->reading()->tokensOf($round);

        return [$tokens->calls, $tokens->prompt, $tokens->completion, $tokens->total];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(LedgerEventKind $kind, array $payload): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)))->succeeded());
    }

    /**
     * @return array<string, mixed>
     */
    // Every key UsageLedger writes, because a row is read back whole through its payload class.
    private function usage(): array
    {
        return ['account' => null, 'model' => 'big', 'prompt' => 100, 'completion' => 20, 'thinking' => null, 'tool' => null, 'cached' => null, 'cache_creation' => null, 'cache_read' => null, 'total' => 120, 'remaining' => null, 'remaining_minute' => null, 'remaining_month' => null];
    }

    private function reading(?Ledger $ledger = null): RoundLedgerReading
    {
        return new RoundLedgerReading($ledger ?? $this->ledger, new LedgerPayloads(StoredSerializerFactory::create()), new ConfiguredPrices(['big' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]]));
    }
}
