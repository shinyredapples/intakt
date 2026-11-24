<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowStatus;

use DateTimeImmutable;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\SharedHold;
use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Dashboard\Domain\Board;
use Intakt\Core\Dashboard\Domain\BoardColumn;
use Intakt\Core\Dashboard\Domain\FailingHead;
use Intakt\Core\Dashboard\Domain\HeadFault;
use Intakt\Core\Dashboard\Domain\Review;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewerOutcome;
use Intakt\Core\Dashboard\Domain\ReviewerProgress;
use Intakt\Core\Dashboard\Domain\RoundFinding;
use Intakt\Core\Dashboard\Domain\RoundPlace;
use Intakt\Core\Dashboard\Domain\RoundStart;
use Intakt\Core\Dashboard\Infrastructure\LedgerBuildSteps;
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\LedgerWaitingHeads;
use Intakt\Core\Dashboard\Infrastructure\MessageQueues;
use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\UseCase\ShowStatus\ProjectStatuses;
use Intakt\Core\Dashboard\UseCase\ShowStatus\StatusReadModel;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\PayloadFields;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Core\Review\Contract\RoundEnding;
use Intakt\Core\Review\Contract\RoundEndingLookup;
use Intakt\Core\Review\Contract\RoundEndings;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\ReplayMarkBus;
use Intakt\Tests\Support\StoredSerializerFactory;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Messenger\Transport\Receiver\ReceiverInterface;

#[CoversClass(StatusReadModel::class)]
#[CoversClass(ProjectStatuses::class)]
#[CoversClass(LedgerWaitingHeads::class)]
#[CoversClass(MessageQueues::class)]
#[CoversClass(LedgerBuildSteps::class)]
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(ReplayMarks::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(PayloadFields::class)]
#[UsesClass(LedgerTokens::class)]
#[UsesClass(RoundPayloads::class)]
#[UsesClass(LedgerPayloads::class)]
#[UsesClass(RecordedEvent::class)]
#[UsesClass(Board::class)]
#[UsesClass(BoardColumn::class)]
#[UsesClass(Review::class)]
#[UsesClass(ReviewedRound::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundFinding::class)]
#[UsesClass(RoundPlace::class)]
#[UsesClass(RoundStart::class)]
#[UsesClass(FailingHead::class)]
#[UsesClass(HeadFault::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
#[UsesClass(RoundEnding::class)]
final class StatusReadModelTest extends TestCase
{
    private const string SCHEMA = __DIR__.'/../../../../../docs/user/status.schema.json';

    private DashboardLedger $ledger;

    private ?Ledger $readFrom = null;

    /** @var list<SharedHold> */
    private array $holds = [];

    /** @var array<string, ReceiverInterface> */
    private array $receivers = [];

    /** @var list<string> */
    private array $replays = [];

    /** @var array<string, ReplayMark> */
    private array $storedMarks = [];

    private ?RoundEnding $ending = null;

    /** @var array<string, RoundEnding> */
    private array $endings = [];

    public function testTheStatusValidatesAgainstTheDocumentedSchema(): void
    {
        $this->holds = [SharedHold::Drain, SharedHold::Pause];
        $this->receivers = ['async' => $this->queueHolding(3), 'failed' => new InMemoryTransport()];
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->startReviewer('r1', 'correctness');
        $this->ledger->answer('r1', 'correctness', 1);
        $this->ledger->startReviewer('r1', 'security');
        $this->ledger->failReviewer('r1', 'security', 'stopped at its output limit');
        $this->ledger->judgeFindings('r1', ['F1' => 'act']);
        $this->ledger->startRecordedRoundOf('7', 'r2', 2);
        $this->ledger->startReviewer('r2', 'correctness');
        $this->ledger->keepHeadPending('alpha', '8', 'b');
        $this->ledger->recordRedHead('alpha', '9', 'c');
        $this->ledger->failHead('alpha', '10', 2);
        $this->ledger->handOverHead('alpha', '11', 'a');
        $this->ledger->passBuildStep('alpha', '12', 'task');
        $this->ledger->failBuildStep('12', 'mirror', 'git fetch failed');
        $this->ledger->passBuildStep('alpha', '13', 'clone');

        $json = $this->readModel()->json();

        self::assertSame([], $this->schemaErrorsOf($json));
    }

    public function testAStoredAskedBuildStepIsLeftOutSoTheStatusStillValidatesAgainstTheSchema(): void
    {
        $this->ledger->passBuildStep('alpha', '12', 'task');
        $this->ledger->recordBuildStep(['project' => 'alpha', 'task' => '12', 'step' => 'build_job', 'outcome' => 'asked', 'failure' => null]);

        $json = $this->readModel()->json();

        self::assertSame([], $this->schemaErrorsOf($json));
    }

    public function testAStoppedConsumerStillYieldsAStatusWithTheRoundWaitingForItsReviewers(): void
    {
        $this->receivers = ['async' => $this->queueHolding(2)];
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);

        $status = $this->read();

        self::assertSame([['name' => 'async', 'messages' => 2]], $status['queues']);
        self::assertSame([['change' => '7', 'mark' => 'live', 'round' => 'r1', 'number' => 1, 'started_at' => '2026-10-28T12:00:00+00:00', 'reviewers' => 2, 'steps' => []]], $this->projectOf($status, 'alpha')['lanes']['review']);
        self::assertSame([], $this->schemaErrorsOf($this->readModel()->json()));
    }

    public function testAQueueThatCannotCountItsMessagesReportsNoCount(): void
    {
        // A bare receiver, because Symfony 8.2's in-memory transport counts its messages.
        $this->receivers = ['async' => self::createStub(ReceiverInterface::class)];

        self::assertSame([['name' => 'async', 'messages' => null]], $this->read()['queues']);
    }

    public function testTheInstallWideHoldsAreReportedByNameWithoutTheirEnd(): void
    {
        $this->holds = [SharedHold::Drain, SharedHold::Pause];

        self::assertSame(['drain', 'pause'], $this->read()['holds']);
    }

    public function testTheStatusNamesTheMomentItWasRead(): void
    {
        self::assertSame('2026-10-28T12:30:00+00:00', $this->read()['generated_at']);
    }

    public function testEachReviewerOfARunningRoundIsAStep(): void
    {
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->startReviewer('r1', 'correctness');
        $this->ledger->answer('r1', 'correctness', 0);
        $this->ledger->startReviewer('r1', 'security');

        $steps = array_column($this->projectOf($this->read(), 'alpha')['lanes']['review'], 'steps');

        self::assertSame([[['role' => 'correctness', 'tier' => 'standard', 'progress' => 'verdict'], ['role' => 'security', 'tier' => 'standard', 'progress' => 'running']]], $steps);
    }

    public function testATaskThatStoppedAtTheMirrorShowsTheMirrorStepAndItsFailure(): void
    {
        $this->ledger->passBuildStep('alpha', '12', 'task');
        $this->ledger->failBuildStep('12', 'mirror', 'git fetch failed: remote not found');

        $lane = $this->projectOf($this->read(), 'alpha')['lanes']['build'];

        self::assertSame([['task' => '12', 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 'git fetch failed: remote not found', 'ended_at' => '2026-10-28T12:00:00+00:00']], $lane);
    }

    public function testATaskThatStoppedAtTheCloneShowsTheCloneStepAndItsFailure(): void
    {
        $this->ledger->passBuildStep('alpha', '12', 'task');
        $this->ledger->passBuildStep('alpha', '12', 'mirror');
        $this->ledger->failBuildStep('12', 'clone', 'the folder exists already');

        $lane = $this->projectOf($this->read(), 'alpha')['lanes']['build'];

        self::assertSame([['task' => '12', 'step' => 'clone', 'outcome' => 'failed', 'failure' => 'the folder exists already', 'ended_at' => '2026-10-28T12:00:00+00:00']], $lane);
    }

    public function testATaskWhoseStepsAllPassedShowsItsCloneStepPassed(): void
    {
        $this->ledger->passBuildStep('alpha', '12', 'task');
        $this->ledger->passBuildStep('alpha', '12', 'mirror');
        $this->ledger->passBuildStep('alpha', '12', 'clone');

        $lane = $this->projectOf($this->read(), 'alpha')['lanes']['build'];

        self::assertSame([['task' => '12', 'step' => 'clone', 'outcome' => 'passed', 'failure' => null, 'ended_at' => '2026-10-28T12:00:00+00:00']], $lane);
    }

    public function testATaskThatWentThroughThePushShowsItsPushStepPassed(): void
    {
        foreach (BuildStep::cases() as $step) {
            $this->ledger->passBuildStep('alpha', '12', $step->value);
        }

        $lane = $this->projectOf($this->read(), 'alpha')['lanes']['build'];

        self::assertSame([['task' => '12', 'step' => 'push', 'outcome' => 'passed', 'failure' => null, 'ended_at' => '2026-10-28T12:00:00+00:00']], $lane);
    }

    public function testATaskThatStoppedAtThePushShowsThePushStepAndItsFailure(): void
    {
        foreach (['task', 'mirror', 'clone', 'build_job', 'hand_back_check', 'pre_push_check'] as $step) {
            $this->ledger->passBuildStep('alpha', '12', $step);
        }
        $this->ledger->failBuildStep('12', 'push', 'the remote refused the push of task-12 (git push exited with 1)');

        $lane = $this->projectOf($this->read(), 'alpha')['lanes']['build'];

        self::assertSame([['task' => '12', 'step' => 'push', 'outcome' => 'failed', 'failure' => 'the remote refused the push of task-12 (git push exited with 1)', 'ended_at' => '2026-10-28T12:00:00+00:00']], $lane);
    }

    public function testAPushFailureIsRedactedBeforeItIsReported(): void
    {
        $this->ledger->failBuildStep('12', 'push', 'the forge refused the write for token-abc');

        $json = $this->readModel(redacting: 'token-abc')->json();

        self::assertStringNotContainsString('token-abc', $json);
        self::assertStringContainsString('the forge refused the write for [redacted]', $json);
    }

    public function testATaskThatIsStillRunningShowsTheLastStepItPassed(): void
    {
        $this->ledger->passBuildStep('alpha', '12', 'task');

        self::assertSame([['task', 'passed']], array_map(static fn (array $task): array => [$task['step'], $task['outcome']], $this->projectOf($this->read(), 'alpha')['lanes']['build']));
    }

    public function testATaskBuiltAgainShowsWhereItsLatestRunIs(): void
    {
        $this->ledger->passBuildStep('alpha', '12', 'task');
        $this->ledger->failBuildStep('12', 'mirror', 'git fetch failed');
        $this->ledger->failBuildStep('12', 'task', 'project "alpha" has no open issue 12');

        self::assertSame([['task', 'failed']], array_map(static fn (array $task): array => [$task['step'], $task['outcome']], $this->projectOf($this->read(), 'alpha')['lanes']['build']));
    }

    public function testTheTasksOfABuildLaneAreListedNewestFirstUnderTheirProject(): void
    {
        $this->ledger->passBuildStep('alpha', '12', 'task');
        $this->ledger->passBuildStep('alpha', '13', 'task');
        $this->ledger->passBuildStep('beta', '14', 'task');
        $this->ledger->passBuildStep('alpha', '12', 'mirror');

        $status = $this->read();

        self::assertSame(['12', '13'], array_column($this->projectOf($status, 'alpha')['lanes']['build'], 'task'));
        self::assertSame(['14'], array_column($this->projectOf($status, 'beta')['lanes']['build'], 'task'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function malformedBuildSteps(): iterable
    {
        yield 'an unknown step' => [['project' => 'alpha', 'task' => '12', 'step' => 'pull_request', 'outcome' => 'passed', 'failure' => null]];
        yield 'an unknown outcome' => [['project' => 'alpha', 'task' => '12', 'step' => 'mirror', 'outcome' => 'skipped', 'failure' => null]];
        yield 'a failed step without its failure' => [['project' => 'alpha', 'task' => '12', 'step' => 'mirror', 'outcome' => 'failed', 'failure' => null]];
        yield 'a passed step with a failure' => [['project' => 'alpha', 'task' => '12', 'step' => 'mirror', 'outcome' => 'passed', 'failure' => 'git fetch failed']];
        yield 'a failure that is no string' => [['project' => 'alpha', 'task' => '12', 'step' => 'mirror', 'outcome' => 'failed', 'failure' => 128]];
        yield 'a task that is no string' => [['project' => 'alpha', 'task' => 12, 'step' => 'mirror', 'outcome' => 'passed', 'failure' => null]];
        yield 'a step that is no string' => [['project' => 'alpha', 'task' => '12', 'step' => ['mirror'], 'outcome' => 'passed', 'failure' => null]];
        yield 'no project' => [['task' => '12', 'step' => 'mirror', 'outcome' => 'passed', 'failure' => null]];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedBuildSteps')]
    public function testABuildStepTheBuildDoesNotWriteIsLeftOut(array $payload): void
    {
        $this->ledger->passBuildStep('alpha', '12', 'task');
        $this->ledger->recordBuildStep($payload);

        self::assertSame([['task', 'passed']], array_map(static fn (array $task): array => [$task['step'], $task['outcome']], $this->projectOf($this->read(), 'alpha')['lanes']['build']));
    }

    public function testABuildStepFailureIsRedactedBeforeItIsReported(): void
    {
        $this->ledger->failBuildStep('12', 'mirror', 'git fetch failed for token-abc');

        $json = $this->readModel(redacting: 'token-abc')->json();

        self::assertStringNotContainsString('token-abc', $json);
        self::assertStringContainsString('git fetch failed for [redacted]', $json);
    }

    public function testAJudgedRoundIsALastOutcomeAndLeavesTheLane(): void
    {
        $this->ledger->startRoundOfQuorum('7', 'r1', 'all');
        $this->ledger->answer('r1', 'correctness', 0);
        $this->ledger->answer('r1', 'security', 0);
        $this->ledger->judgeRound('r1');

        $project = $this->projectOf($this->read(), 'alpha');

        self::assertSame([], $project['lanes']['review']);
        self::assertSame([['change' => '7', 'mark' => 'live', 'round' => 'r1', 'number' => 1, 'end' => 'judged', 'decision' => 'approve', 'judged_at' => '2026-10-28T12:00:00+00:00']], $project['last_outcomes']);
    }

    public function testTheEarlierJudgedRoundOfAChangeInReviewAgainIsItsLastOutcome(): void
    {
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->judgeRound('r1');
        $this->ledger->startRecordedRoundOf('7', 'r2', 2);

        $project = $this->projectOf($this->read(), 'alpha');

        self::assertSame(['r1'], array_column($project['last_outcomes'], 'round'));
        self::assertSame(['r2'], array_column($project['lanes']['review'], 'round'));
    }

    public function testAJudgedRoundWithoutARecordedQuorumIsALastOutcomeWithoutADecision(): void
    {
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->judgeRound('r1');

        self::assertSame([null], array_column($this->projectOf($this->read(), 'alpha')['last_outcomes'], 'decision'));
    }

    public function testARoundWhoseJudgingFailedForGoodIsAFailedLastOutcomeAndLeavesTheLane(): void
    {
        $this->ending = new RoundEnding(RoundEnds::JudgingFailed, 'the judging failed');
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->answer('r1', 'correctness', 0);

        $project = $this->projectOf($this->read(), 'alpha');

        self::assertSame([], $project['lanes']['review']);
        self::assertSame([['change' => '7', 'mark' => 'live', 'round' => 'r1', 'number' => 1, 'end' => 'judging_failed', 'decision' => null, 'judged_at' => null]], $project['last_outcomes']);
    }

    public function testAJudgingThatFailedForGoodAfterAnEarlierJudgedRoundIsTheChangesLastOutcome(): void
    {
        $this->ending = new RoundEnding(RoundEnds::JudgingFailed, 'the judging failed');
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->judgeRound('r1');
        $this->ledger->startRecordedRoundOf('7', 'r2', 2);

        self::assertSame([['r2', 'judging_failed']], array_map(static fn (array $entry): array => [$entry['round'], $entry['end']], $this->projectOf($this->read(), 'alpha')['last_outcomes']));
    }

    public function testAFailedJudgingStaysTheChangesLastOutcomeWhileItsNextRoundRuns(): void
    {
        $this->endings = ['r1' => new RoundEnding(RoundEnds::JudgingFailed, 'the judging failed')];
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->startRecordedRoundOf('7', 'r2', 2);

        $project = $this->projectOf($this->read(), 'alpha');

        self::assertSame([['r1', 'judging_failed']], array_map(static fn (array $entry): array => [$entry['round'], $entry['end']], $project['last_outcomes']));
        self::assertSame(['r2'], array_column($project['lanes']['review'], 'round'));
    }

    public function testAFailedJudgingAfterAJudgedRoundStaysTheLastOutcomeWhileAThirdRoundRuns(): void
    {
        $this->endings = ['r2' => new RoundEnding(RoundEnds::JudgingFailed, 'the judging failed')];
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->judgeRound('r1');
        $this->ledger->startRecordedRoundOf('7', 'r2', 2);
        $this->ledger->startRecordedRoundOf('7', 'r3', 3);

        self::assertSame([['r2', 'judging_failed']], array_map(static fn (array $entry): array => [$entry['round'], $entry['end']], $this->projectOf($this->read(), 'alpha')['last_outcomes']));
    }

    public function testAJudgingFailureRecordedAfterTenJudgmentsIsTheNewestOfTheTenLatestOutcomes(): void
    {
        $this->endings = ['failed' => new RoundEnding(RoundEnds::JudgingFailed, 'the judging failed', new DateTimeImmutable('2026-10-28T12:05:00+00:00'))];
        $this->ledger->startRecordedRoundOf('11', 'failed', 1);

        foreach (range(1, 10) as $change) {
            $this->ledger->startRecordedRoundOf((string) $change, 'r'.$change, 1);
            $this->ledger->judgeRoundAt('r'.$change, new DateTimeImmutable('2026-10-28T12:01:00+00:00'));
        }

        $outcomes = $this->projectOf($this->read(), 'alpha')['last_outcomes'];

        self::assertCount(10, $outcomes);
        self::assertSame(['failed', 'judging_failed'], [$outcomes[0]['round'], $outcomes[0]['end']]);
    }

    public function testARoundThatFailedInItsJudgingStillValidatesAgainstTheDocumentedSchema(): void
    {
        $this->ending = new RoundEnding(RoundEnds::JudgingFailed, 'the judging failed');
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);

        self::assertSame([], $this->schemaErrorsOf($this->readModel()->json()));
    }

    public function testOnlyTheTenLatestOutcomesOfAProjectAreListed(): void
    {
        foreach (range(1, 11) as $change) {
            $this->ledger->startRecordedRoundOf((string) $change, 'r'.$change, 1);
            $this->ledger->judgeRound('r'.$change);
        }

        self::assertCount(10, $this->projectOf($this->read(), 'alpha')['last_outcomes']);
    }

    public function testAHeadWaitingForCiIsAPendingGuardDecisionWithItsNextRead(): void
    {
        $this->ledger->keepHeadPending('alpha', '8', 'b');

        $decisions = $this->projectOf($this->read(), 'alpha')['guard_decisions'];

        self::assertSame([['change' => '8', 'decision' => 'pending', 'cause' => null, 'failures' => null, 'read_again_at' => '2026-10-28T12:01:00+00:00']], $decisions);
    }

    public function testAMalformedNextReadOfAWaitingHeadIsReportedAsNone(): void
    {
        $this->ledger->keepHeadPendingUntil('alpha', '8', 'next tuesday');

        self::assertSame([null], array_column($this->projectOf($this->read(), 'alpha')['guard_decisions'], 'read_again_at'));
        self::assertSame([], $this->schemaErrorsOf($this->readModel()->json()));
    }

    public function testANextReadOfAWaitingHeadThatPhpWouldRollOverIsReportedAsNone(): void
    {
        $this->ledger->keepHeadPendingUntil('alpha', '8', '2026-02-30T12:01:00+00:00');

        self::assertSame([null], array_column($this->projectOf($this->read(), 'alpha')['guard_decisions'], 'read_again_at'));
        self::assertSame([], $this->schemaErrorsOf($this->readModel()->json()));
    }

    public function testANextReadWithANulByteOfAWaitingHeadIsReportedAsNone(): void
    {
        $this->ledger->keepHeadPendingUntil('alpha', '8', "2026-10-28T12:01:00+00:00\0");

        self::assertSame([null], array_column($this->projectOf($this->read(), 'alpha')['guard_decisions'], 'read_again_at'));
    }

    public function testAnImpossibleCalendarDateAsTheNextReadOfAWaitingHeadIsReportedAsNone(): void
    {
        $this->ledger->keepHeadPendingUntil('alpha', '8', '2026-02-30T00:00:00+00:00');

        self::assertSame([null], array_column($this->projectOf($this->read(), 'alpha')['guard_decisions'], 'read_again_at'));
    }

    public function testAValidNextReadOfAWaitingHeadIsReportedAsStored(): void
    {
        $this->ledger->keepHeadPendingUntil('alpha', '8', '2026-02-28T23:59:00+02:00');

        self::assertSame(['2026-02-28T23:59:00+02:00'], array_column($this->projectOf($this->read(), 'alpha')['guard_decisions'], 'read_again_at'));
    }

    public function testARedHeadIsARedGuardDecision(): void
    {
        $this->ledger->recordRedHead('alpha', '9', 'c');

        self::assertSame(['red'], array_column($this->projectOf($this->read(), 'alpha')['guard_decisions'], 'decision'));
    }

    public function testAFailedHeadIsAFailedGuardDecisionWithItsCauseAndNextTry(): void
    {
        $this->ledger->failHead('alpha', '10', 2);

        $decisions = $this->projectOf($this->read(), 'alpha')['guard_decisions'];

        self::assertSame([['change' => '10', 'decision' => 'failed', 'cause' => 'RuntimeException: commit a not fetched', 'failures' => 2, 'read_again_at' => '2026-10-28T12:08:00+00:00']], $decisions);
    }

    public function testAHeadWhoseRoundIsQueuedHasNoGuardDecision(): void
    {
        $this->ledger->keepHeadPending('alpha', '8', 'b');
        $this->ledger->queueRoundOf('alpha', '8', 'b');

        self::assertSame([], $this->projectOf($this->read(), 'alpha')['guard_decisions']);
    }

    public function testAHeadReadAgainAfterItFailedIsNoLongerFailed(): void
    {
        $this->ledger->failHead('alpha', '8', 1);
        $this->ledger->keepHeadPending('alpha', '8', 'a');

        self::assertSame(['pending'], array_column($this->projectOf($this->read(), 'alpha')['guard_decisions'], 'decision'));
    }

    public function testAHeadTheWatcherReadsAgainWhileTheStatusIsReadIsReportedOnce(): void
    {
        $this->ledger->failHead('alpha', '8', 1);
        $this->readFrom = new LedgerWrittenAfterRead($this->ledger, 2, fn () => $this->ledger->keepHeadPending('alpha', '8', 'a'));

        self::assertSame(['8'], array_column($this->projectOf($this->read(), 'alpha')['guard_decisions'], 'change'));
    }

    public function testAHandedOverHeadIsANeedsYouItemAndNoGuardDecision(): void
    {
        $this->ledger->handOverHead('alpha', '11', 'a');

        $project = $this->projectOf($this->read(), 'alpha');

        self::assertSame([['change' => '11', 'cause' => 'RuntimeException: commit a not fetched', 'failures' => 4]], $project['needs_you']);
        self::assertSame([], $project['guard_decisions']);
    }

    public function testAFailureCauseIsRedactedBeforeItIsReported(): void
    {
        $this->ledger->failHead('alpha', '10', 1);
        $this->ledger->handOverHead('alpha', '11', 'a');

        $json = $this->readModel(redacting: 'commit a')->json();

        self::assertStringNotContainsString('commit a', $json);
        self::assertStringContainsString('RuntimeException: [redacted] not fetched', $json);
    }

    public function testNothingWaitsForTheOwnerWhileNoCardIsInWaitingOnYou(): void
    {
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);

        self::assertSame([], $this->projectOf($this->read(), 'alpha')['waiting_for_owner']);
    }

    public function testAWatchedProjectWithoutEventsIsListedEmpty(): void
    {
        $project = $this->projectOf($this->read(), 'beta');

        self::assertSame(['name' => 'beta', 'mark' => 'live', 'lanes' => ['review' => [], 'build' => []], 'guard_decisions' => [], 'waiting_for_owner' => [], 'needs_you' => [], 'last_outcomes' => []], $project);
    }

    public function testProjectsAreListedByNameAndEachOnce(): void
    {
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->keepHeadPending('gamma', '8', 'b');
        $this->ledger->failHead('42', '9', 1);

        self::assertSame(['42', 'alpha', 'beta', 'gamma'], array_column($this->read()['projects'], 'name'));
    }

    public function testTheSchemaNamesEveryHoldReviewerProgressAndDecisionTheCodeHas(): void
    {
        $project = ['properties', 'projects', 'items', 'properties'];

        self::assertSame(array_column(SharedHold::cases(), 'value'), $this->schemaAt('properties', 'holds', 'items', 'enum'));
        self::assertSame(array_column(ReviewerProgress::cases(), 'value'), $this->schemaAt(...$project, ...['lanes', 'properties', 'review', 'items', 'properties', 'steps', 'items', 'properties', 'progress', 'enum']));
        self::assertSame([...array_column(Decision::cases(), 'value'), null], $this->schemaAt(...$project, ...['last_outcomes', 'items', 'properties', 'decision', 'enum']));
    }

    public function testTheSchemaNamesEveryRoundEndTheCodeHas(): void
    {
        self::assertSame(array_column(RoundEnds::cases(), 'value'), $this->schemaAt('properties', 'projects', 'items', 'properties', 'last_outcomes', 'items', 'properties', 'end', 'enum'));
    }

    public function testAProjectFlaggedAsReplayAndItsReviewsAreMarkedAsReplay(): void
    {
        $this->replays = ['alpha'];
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->judgeFindings('r1', ['F1' => 'act']);
        $this->ledger->startRecordedRoundOf('8', 'r2', 1);

        $alpha = $this->projectOf($this->read(), 'alpha');

        self::assertSame(['replay', ['replay'], ['replay']], [$alpha['mark'], array_column($alpha['lanes']['review'], 'mark'), array_column($alpha['last_outcomes'], 'mark')]);
    }

    public function testAProjectNeverFlaggedAndItsReviewsAreMarkedAsLive(): void
    {
        $this->replays = ['beta'];
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->judgeFindings('r1', ['F1' => 'act']);
        $this->ledger->startRecordedRoundOf('8', 'r2', 1);

        $alpha = $this->projectOf($this->read(), 'alpha');

        self::assertSame(['live', ['live'], ['live']], [$alpha['mark'], array_column($alpha['lanes']['review'], 'mark'), array_column($alpha['last_outcomes'], 'mark')]);
    }

    public function testTheReviewLaneTakesTheReplayMarkOfALatestReplayRoundOpenedAfterALiveRound(): void
    {
        $this->replays = ['alpha'];
        $this->storedMarks = ['r1' => ReplayMark::Live, 'r2' => ReplayMark::Replay];
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->judgeRound('r1');
        $this->ledger->startRecordedRoundOf('7', 'r2', 2);

        $alpha = $this->projectOf($this->read(), 'alpha');

        self::assertSame([['r2', 'replay']], array_map(static fn (array $entry): array => [$entry['round'], $entry['mark']], $alpha['lanes']['review']));
    }

    public function testALastOutcomeTakesTheMarkOfItsJudgedRoundNotOfTheLatestRound(): void
    {
        $this->storedMarks = ['r1' => ReplayMark::Live, 'r2' => ReplayMark::Replay];
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);
        $this->ledger->judgeRound('r1');
        $this->ledger->startRecordedRoundOf('7', 'r2', 2);

        $alpha = $this->projectOf($this->read(), 'alpha');

        self::assertSame([['r1', 'live']], array_map(static fn (array $entry): array => [$entry['round'], $entry['mark']], $alpha['last_outcomes']));
    }

    public function testAWatchedProjectFlaggedAsReplayIsMarkedBeforeItsFirstReview(): void
    {
        $this->replays = ['beta'];

        self::assertSame('replay', $this->projectOf($this->read(), 'beta')['mark']);
    }

    public function testTheSchemaNamesEveryReplayMarkTheCodeHas(): void
    {
        self::assertSame(array_column(ReplayMark::cases(), 'value'), $this->schemaAt('definitions', 'mark', 'enum'));
    }

    public function testTheSchemaNamesEveryBuildStepAndStepOutcomeTheCodeHas(): void
    {
        $build = ['properties', 'projects', 'items', 'properties', 'lanes', 'properties', 'build', 'items', 'properties'];

        self::assertSame(array_column(BuildStep::cases(), 'value'), $this->schemaAt(...$build, ...['step', 'enum']));
        // The schema is one case behind, because no step is written as asked yet and the published schema names it with the change that writes it.
        self::assertSame(array_column(BuildStepOutcome::cases(), 'value'), [...(array) $this->schemaAt(...$build, ...['outcome', 'enum']), BuildStepOutcome::Asked->value]);
    }

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
    }

    private function schemaAt(string ...$path): mixed
    {
        $node = json_decode((string) file_get_contents(self::SCHEMA), true, flags: JSON_THROW_ON_ERROR);

        foreach ($path as $key) {
            self::assertIsArray($node);
            $node = $node[$key] ?? null;
        }

        return $node;
    }

    /**
     * @return array{generated_at: string, holds: list<string>, queues: list<array<string, mixed>>, projects: list<array{name: string, mark: string, lanes: array{review: list<array<string, mixed>>, build: list<array<string, mixed>>}, guard_decisions: list<array<string, mixed>>, waiting_for_owner: list<array<string, mixed>>, needs_you: list<array<string, mixed>>, last_outcomes: list<array<string, mixed>>}>}
     */
    private function read(): array
    {
        /** @var array{generated_at: string, holds: list<string>, queues: list<array<string, mixed>>, projects: list<array{name: string, mark: string, lanes: array{review: list<array<string, mixed>>, build: list<array<string, mixed>>}, guard_decisions: list<array<string, mixed>>, waiting_for_owner: list<array<string, mixed>>, needs_you: list<array<string, mixed>>, last_outcomes: list<array<string, mixed>>}>} $status */
        $status = json_decode($this->readModel()->json(), true, flags: JSON_THROW_ON_ERROR);

        return $status;
    }

    /**
     * @param array{projects: list<array{name: string, mark: string, lanes: array{review: list<array<string, mixed>>, build: list<array<string, mixed>>}, guard_decisions: list<array<string, mixed>>, waiting_for_owner: list<array<string, mixed>>, needs_you: list<array<string, mixed>>, last_outcomes: list<array<string, mixed>>}>} $status
     *
     * @return array{name: string, mark: string, lanes: array{review: list<array<string, mixed>>, build: list<array<string, mixed>>}, guard_decisions: list<array<string, mixed>>, waiting_for_owner: list<array<string, mixed>>, needs_you: list<array<string, mixed>>, last_outcomes: list<array<string, mixed>>}
     */
    private function projectOf(array $status, string $name): array
    {
        $project = array_find($status['projects'], static fn (array $project): bool => $name === $project['name']);
        self::assertNotNull($project);

        return $project;
    }

    private function queueHolding(int $messages): ReceiverInterface
    {
        $queue = self::createStubForIntersectionOfInterfaces([ReceiverInterface::class, MessageCountAwareInterface::class]);
        $queue->method('getMessageCount')->willReturn($messages);
        self::assertInstanceOf(ReceiverInterface::class, $queue);

        return $queue;
    }

    private function readModel(string $redacting = ''): StatusReadModel
    {
        $holds = self::createStub(Holds::class);
        $holds->method('sharedInEffect')->willReturn($this->holds);
        $redactor = self::createStub(Redactor::class);
        $redactor->method('redact')->willReturnCallback(static fn (string $text): string => '' === $redacting ? $text : str_replace($redacting, '[redacted]', $text));
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn(Cost::none());
        $ledger = $this->readFrom ?? $this->ledger;
        $marks = new ReplayMarks(new ReplayMarkBus($this->replays, $this->storedMarks));
        $lookup = self::createStub(RoundEndingLookup::class);
        $lookup->method('ofUnjudged')->willReturnCallback(fn (string $round): ?RoundEnding => $this->endings[$round] ?? $this->ending);
        $endings = self::createStub(RoundEndings::class);
        $endings->method('lookup')->willReturn($lookup);
        $pages = new LedgerReviewPages(new MockClock('2026-10-28T12:30:00+00:00'), $ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), $costs, new ChangeDecision(), new FailureInWords(), $endings, $marks);
        $projects = new ProjectStatuses($ledger, $pages, new LedgerFailingHeads($ledger), new LedgerWaitingHeads(), new LedgerBuildSteps(), $redactor, $marks, ['alpha' => [], 'beta' => []]);

        return new StatusReadModel(new MockClock('2026-10-28T12:30:00+00:00'), $holds, new MessageQueues($this->receivers), $projects);
    }

    /**
     * @return list<string>
     */
    private function schemaErrorsOf(string $json): array
    {
        $document = json_decode($json);
        $validator = new Validator();
        $validator->validate($document, (object) ['$ref' => 'file://'.realpath(self::SCHEMA)]);

        /** @var list<array{property: string, message: string}> $errors */
        $errors = $validator->getErrors();

        return array_map(static fn (array $error): string => $error['property'].': '.$error['message'], $errors);
    }
}
