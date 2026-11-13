<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\UseCase\ShowStatus;

use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Contract\Holds;
use Intakt\Core\Budget\Contract\SharedHold;
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
use Intakt\Core\Dashboard\Infrastructure\LedgerFailingHeads;
use Intakt\Core\Dashboard\Infrastructure\LedgerPayload;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviewPages;
use Intakt\Core\Dashboard\Infrastructure\LedgerRounds;
use Intakt\Core\Dashboard\Infrastructure\LedgerTokens;
use Intakt\Core\Dashboard\Infrastructure\LedgerWaitingHeads;
use Intakt\Core\Dashboard\Infrastructure\MessageQueues;
use Intakt\Core\Dashboard\Infrastructure\RoundPayloads;
use Intakt\Core\Dashboard\UseCase\ShowStatus\ProjectStatuses;
use Intakt\Core\Dashboard\UseCase\ShowStatus\StatusReadModel;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\RoundCosts;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Core\Dashboard\UseCase\DashboardLedger;
use Intakt\Tests\Support\StoredSerializerFactory;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
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
#[UsesClass(LedgerReviewPages::class)]
#[UsesClass(LedgerRounds::class)]
#[UsesClass(LedgerFailingHeads::class)]
#[UsesClass(LedgerPayload::class)]
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
final class StatusReadModelTest extends TestCase
{
    private const string SCHEMA = __DIR__.'/../../../../../docs/user/status.schema.json';

    private DashboardLedger $ledger;

    /** @var list<SharedHold> */
    private array $holds = [];

    /** @var array<string, ReceiverInterface> */
    private array $receivers = [];

    protected function setUp(): void
    {
        $this->ledger = new DashboardLedger();
    }

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

        $json = $this->readModel()->json();

        self::assertSame([], $this->schemaErrorsOf($json));
    }

    public function testAStoppedConsumerStillYieldsAStatusWithTheRoundWaitingForItsReviewers(): void
    {
        $this->receivers = ['async' => $this->queueHolding(2)];
        $this->ledger->startRecordedRoundOf('7', 'r1', 1);

        $status = $this->read();

        self::assertSame([['name' => 'async', 'messages' => 2]], $status['queues']);
        self::assertSame([['change' => '7', 'round' => 'r1', 'number' => 1, 'started_at' => '2026-10-28T12:00:00+00:00', 'reviewers' => 2, 'steps' => []]], $this->projectOf($status, 'alpha')['lanes']['review']);
        self::assertSame([], $this->schemaErrorsOf($this->readModel()->json()));
    }

    public function testAQueueThatCannotCountItsMessagesReportsNoCount(): void
    {
        $this->receivers = ['async' => new InMemoryTransport()];

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

        $steps = $this->projectOf($this->read(), 'alpha')['lanes']['review'][0]['steps'];

        self::assertSame([['role' => 'correctness', 'tier' => 'standard', 'progress' => 'verdict'], ['role' => 'security', 'tier' => 'standard', 'progress' => 'running']], $steps);
    }

    public function testAJudgedRoundIsALastOutcomeAndLeavesTheLane(): void
    {
        $this->ledger->startRoundOfQuorum('7', 'r1', 'all');
        $this->ledger->answer('r1', 'correctness', 0);
        $this->ledger->answer('r1', 'security', 0);
        $this->ledger->judgeRound('r1');

        $project = $this->projectOf($this->read(), 'alpha');

        self::assertSame([], $project['lanes']['review']);
        self::assertSame([['change' => '7', 'round' => 'r1', 'number' => 1, 'decision' => 'approve', 'judged_at' => '2026-10-28T12:00:00+00:00']], $project['last_outcomes']);
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

        self::assertSame(['name' => 'beta', 'lanes' => ['review' => []], 'guard_decisions' => [], 'waiting_for_owner' => [], 'needs_you' => [], 'last_outcomes' => []], $project);
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
        $schema = json_decode((string) file_get_contents(self::SCHEMA), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);
        $project = $schema['properties']['projects']['items']['properties'];

        self::assertSame(array_column(SharedHold::cases(), 'value'), $schema['properties']['holds']['items']['enum']);
        self::assertSame(array_column(ReviewerProgress::cases(), 'value'), $project['lanes']['properties']['review']['items']['properties']['steps']['items']['properties']['progress']['enum']);
        self::assertSame([...array_column(Decision::cases(), 'value'), null], $project['last_outcomes']['items']['properties']['decision']['enum']);
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $status = json_decode($this->readModel()->json(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($status);

        /** @var array<string, mixed> $status */
        return $status;
    }

    /**
     * @param array<string, mixed> $status
     *
     * @return array<string, mixed>
     */
    private function projectOf(array $status, string $name): array
    {
        self::assertIsArray($status['projects']);
        $project = array_find($status['projects'], static fn (mixed $project): bool => is_array($project) && $name === $project['name']);
        self::assertIsArray($project);

        /** @var array<string, mixed> $project */
        return $project;
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

    private function readModel(string $redacting = ''): StatusReadModel
    {
        $holds = self::createStub(Holds::class);
        $holds->method('sharedInEffect')->willReturn($this->holds);
        $redactor = self::createStub(Redactor::class);
        $redactor->method('redact')->willReturnCallback(static fn (string $text): string => '' === $redacting ? $text : str_replace($redacting, '[redacted]', $text));
        $costs = self::createStub(RoundCosts::class);
        $costs->method('costOf')->willReturn(Cost::none());
        $pages = new LedgerReviewPages($this->ledger, new RoundPayloads(new LedgerPayloads(StoredSerializerFactory::create())), $costs, new ChangeDecision(), new FailureInWords());
        $projects = new ProjectStatuses($pages, new LedgerFailingHeads($this->ledger), new LedgerWaitingHeads($this->ledger), $redactor, ['alpha' => [], 'beta' => []]);

        return new StatusReadModel(new MockClock('2026-10-28T12:30:00+00:00'), $holds, new MessageQueues($this->receivers), $projects);
    }

    private function queueHolding(int $messages): ReceiverInterface
    {
        $queue = self::createStubForIntersectionOfInterfaces([ReceiverInterface::class, MessageCountAwareInterface::class]);
        $queue->method('getMessageCount')->willReturn($messages);

        return $queue;
    }
}
