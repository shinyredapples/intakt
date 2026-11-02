<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\JudgeRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FollowUp;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\RoundJudged;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\FindingLedger;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\Domain\RoundTransition;
use Intakt\Core\Review\UseCase\JudgeRound\JudgedRounds;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeLimited;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\JudgeRound\QueueJudgingOfCompletedRound;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\Vendor\Contract\ProviderFailedTransiently;
use Intakt\Plugin\Vendor\Contract\ProviderRateLimited;
use Intakt\Plugin\Vendor\Contract\VendorAccountBusy;
use Intakt\Plugin\Vendor\Contract\VendorAccountOut;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

#[Group('persistence')]
#[CoversClass(JudgeRoundHandler::class)]
#[CoversClass(JudgeRound::class)]
#[CoversClass(JudgedRounds::class)]
#[CoversClass(JudgeLimited::class)]
#[CoversClass(QueueJudgingOfCompletedRound::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(ReviewRounds::class)]
#[CoversClass(StoredFindings::class)]
#[CoversClass(FindingLedger::class)]
#[CoversClass(RoundTransition::class)]
#[UsesClass(JudgedFindings::class)]
#[UsesClass(RoundJudged::class)]
#[UsesClass(FollowUp::class)]
#[UsesClass(Judgement::class)]
#[UsesClass(JudgedFinding::class)]
#[UsesClass(Finding::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(ReviewRound::class)]
final class JudgeRoundTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testACompletedRoundIsQueuedForJudgingOnce(): void
    {
        $round = $this->completedRound();

        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertEquals([new JudgeRound($round->id()->toRfc4122())], $this->fixture->judgings());
    }

    public function testARoundStillCollectingIsNotQueuedForJudging(): void
    {
        $this->fixture->start(2);
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertSame([], $this->fixture->judgings());
    }

    public function testJudgingARoundRecordsJudgeDoneWithTheActIdsAndTheFollowUpIds(): void
    {
        $round = $this->completedRound();
        $judgement = new Judgement([new JudgedFinding('F1', Mark::Act), new JudgedFinding('F2', Mark::Defer), new JudgedFinding('F3', Mark::Reject)]);

        $this->fixture->judge(new ScriptedJudgement($judgement))($this->judgingOf($round));

        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => ['F1'], 'follow_ups' => ['F2']]], $this->judgeCalls());
    }

    public function testJudgeDoneHoldsEveryJudgedFindingWithItsLedgerIdSeverityAndText(): void
    {
        $round = $this->completedRound();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame(
            [['id' => 'F1', 'severity' => 'P1', 'text' => 'Text of A.'], ['id' => 'F2', 'severity' => 'P2', 'text' => 'Text of B.'], ['id' => 'F3', 'severity' => 'P3', 'text' => 'Text of C.']],
            $this->judgeDonePayloads()[0]['findings'],
        );
    }

    public function testJudgingARoundMovesItToJudged(): void
    {
        $round = $this->completedRound();

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame(RoundState::Judged, $this->fixture->round($round->id()->toRfc4122())->state());
    }

    public function testAJudgedRoundAnnouncesTheCheckoutItsReviewersRead(): void
    {
        $round = $this->completedRound();
        $announced = [];
        $this->fixture->events->addListener(RoundJudged::class, static function (RoundJudged $judged) use (&$announced): void {
            $announced[] = $judged;
        });

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertEquals([new RoundJudged($round->id()->toRfc4122(), $round->checkoutRoot())], $announced);
    }

    public function testARoundJudgedAlreadyIsNotAnnouncedAgain(): void
    {
        $round = $this->completedRound();
        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));
        $announced = 0;
        $this->fixture->events->addListener(RoundJudged::class, static function () use (&$announced): void {
            ++$announced;
        });

        $this->fixture->judge(new ScriptedJudgement(new Judgement([])))($this->judgingOf($round));

        self::assertSame(0, $announced);
    }

    public function testTheJudgeIsAskedAboutTheFindingsOfTheRoundUnderTheLedgerIdsWithItsTask(): void
    {
        $round = $this->completedRound();
        $vendor = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($vendor)($this->judgingOf($round));

        $asked = $vendor->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame(RoundFixture::TASK, $asked->task);
        self::assertSame([['F1', 'Text of A.'], ['F2', 'Text of B.'], ['F3', 'Text of C.']], $this->idsAndTexts($asked));
    }

    public function testTwoReviewersReportingDifferentFindingsUnderOneIdBothReachTheJudge(): void
    {
        $round = $this->fixture->start(2);
        $vendor = new VerdictPerRole([
            'Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], []),
            'Look at part 2.' => new Verdict(Decision::Changes, [new Finding('A', Severity::P1, null, null, null, 'Another defect.', FixableBy::Builder, false, null, null)], [], []),
        ]);
        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)($this->judgingOf($round));

        $asked = $judge->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame([['F1', 'Text of A.'], ['F2', 'Another defect.']], $this->idsAndTexts($asked));
    }

    public function testAnOlderRoundCompletingAfterANewerOneStillSendsItsFindingsToTheJudge(): void
    {
        $older = $this->fixture->start(1);
        $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[1], new VerdictPerRole([]));
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], [])]));
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)($this->judgingOf($older));

        $asked = $judge->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame([['R1-F1', 'Text of A.']], $this->idsAndTexts($asked));
    }

    public function testANewFindingOfAnOlderRoundCompletingAfterANewerOneKeepsAnIdNoLaterRoundIsGiven(): void
    {
        $older = $this->fixture->start(1);
        $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[1], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('B', Severity::P1)], [], [])]));
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], [])]));
        $later = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[2], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('C', Severity::P1)], [], [])]));

        self::assertSame([['F2', 'Text of C.']], $this->askedAbout($later));
        self::assertSame([['R1-F2', 'Text of A.']], $this->askedAbout($older));
    }

    public function testARoundThatReportedNoFindingsIsJudgedWithoutAskingTheJudge(): void
    {
        $round = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));
        $vendor = new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act)]));

        $this->fixture->judge($vendor)($this->judgingOf($round));

        self::assertSame(0, $vendor->calls);
        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => [], 'follow_ups' => []]], $this->judgeCalls());
        self::assertSame(RoundState::Judged, $this->fixture->round($round->id()->toRfc4122())->state());
    }

    public function testAFindingALaterRoundReportsAgainStillReachesTheJudgeOfTheRoundThatReportedItFirst(): void
    {
        $first = $this->fixture->start(1);
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], [])]));
        $this->fixture->start(1);
        $repeat = new Finding('F1', Severity::P1, null, null, null, 'Text of A, again.', FixableBy::Builder, true, null, null);
        $this->fixture->deliver($this->fixture->bus->messages[1], new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$repeat], [], [])]));
        $judge = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($judge)($this->judgingOf($first));

        $asked = $judge->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);
        self::assertSame([['F1', 'Text of A.']], $this->idsAndTexts($asked));
    }

    public function testAnInvalidJudgeAnswerLeavesEveryBlockingFindingOnTheActListAndMakesNoFollowUp(): void
    {
        $round = $this->completedRound();
        $vendor = new ScriptedJudgement(Judgement::everyBlockingFindingActs([$this->finding('F1', Severity::P1), $this->finding('F2', Severity::P2), $this->finding('F3', Severity::P3)]));

        $this->fixture->judge($vendor)($this->judgingOf($round));

        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => ['F1', 'F2'], 'follow_ups' => []]], $this->judgeCalls());
    }

    public function testAJudgedRoundDeliveredAgainAsksTheJudgeNothingAndRecordsNoSecondJudgeDone(): void
    {
        $round = $this->completedRound();
        $vendor = new ScriptedJudgement(new Judgement([new JudgedFinding('F1', Mark::Act)]));
        $handler = $this->fixture->judge($vendor);

        $handler($this->judgingOf($round));
        $handler($this->judgingOf($round));

        self::assertSame(1, $vendor->calls);
        self::assertCount(1, $this->judgeDonePayloads());
    }

    public function testARoundJudgedByAnotherWorkerDuringTheCallKeepsOnlyThatWorkersJudgeDone(): void
    {
        $round = $this->completedRound();
        $first = new Judgement([new JudgedFinding('F1', Mark::Act)]);
        $second = new Judgement([new JudgedFinding('F1', Mark::Defer)]);
        $otherWorker = fn () => $this->fixture->judge(new ScriptedJudgement($first))($this->judgingOf($round));

        $this->fixture->judge(new ScriptedJudgement($second, $otherWorker))($this->judgingOf($round));

        self::assertSame([['round' => $round->id()->toRfc4122(), 'act' => ['F1'], 'follow_ups' => []]], $this->judgeCalls());
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function limitedCalls(): iterable
    {
        yield 'busy account' => [VendorAccountBusy::at('anthropic')];
        yield 'rate limit' => [new ProviderRateLimited(30, new RuntimeException('limited'))];
        yield 'transient failure' => [new ProviderFailedTransiently(new RuntimeException('down'))];
        yield 'account out' => [new VendorAccountOut(new RuntimeException('out'))];
    }

    #[DataProvider('limitedCalls')]
    public function testALimitedJudgeCallIsRetriedAndRecordsNothing(Throwable $limit): void
    {
        $round = $this->completedRound();

        try {
            $this->fixture->judge(new FailingJudge($limit))($this->judgingOf($round));
            self::fail('The limited call was not reported.');
        } catch (JudgeLimited) {
            self::assertSame([], $this->judgeDonePayloads());
            self::assertSame(RoundState::Complete, $this->fixture->round($round->id()->toRfc4122())->state());
        }
    }

    public function testARateLimitedJudgeCallIsRetriedAfterTheProvidersReset(): void
    {
        $round = $this->completedRound();

        try {
            $this->fixture->judge(new FailingJudge(new ProviderRateLimited(30, new RuntimeException('limited'))))($this->judgingOf($round));
            self::fail('The limited call was not reported.');
        } catch (JudgeLimited $limited) {
            self::assertSame(30_000, $limited->getRetryDelay());
        }
    }

    private function completedRound(): ReviewRound
    {
        $round = $this->fixture->start(2);
        $findings = [$this->finding('A', Severity::P1), $this->finding('B', Severity::P2), $this->finding('C', Severity::P3)];
        $vendor = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, $findings, [], [])]);

        foreach ($this->fixture->bus->messages as $message) {
            $this->fixture->deliver($message, $vendor);
        }

        return $this->fixture->round($round->id()->toRfc4122());
    }

    private function judgingOf(ReviewRound $round): JudgeRound
    {
        return new JudgeRound($round->id()->toRfc4122());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function judgeCalls(): array
    {
        return array_map(static fn (array $payload): array => array_diff_key($payload, ['findings' => true]), $this->judgeDonePayloads());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function judgeDonePayloads(): array
    {
        $events = array_filter(iterator_to_array($this->fixture->ledger()->readInOrder()), static fn (RecordedEvent $event): bool => LedgerEventKind::JudgeDone === $event->event->kind);

        return array_values(array_map(static function (RecordedEvent $event): array {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($event->event->payload, true, 8, JSON_THROW_ON_ERROR);

            return $payload;
        }, $events));
    }

    /**
     * @return list<array{string, string}>
     */
    private function askedAbout(ReviewRound $round): array
    {
        $judge = new ScriptedJudgement(new Judgement([]));
        $this->fixture->judge($judge)($this->judgingOf($round));
        $asked = $judge->asked;
        self::assertInstanceOf(JudgeRequest::class, $asked);

        return $this->idsAndTexts($asked);
    }

    /**
     * @return list<array{string, string}>
     */
    private function idsAndTexts(JudgeRequest $request): array
    {
        return array_map(static fn (Finding $finding): array => [$finding->id, $finding->text], $request->findings);
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }
}
