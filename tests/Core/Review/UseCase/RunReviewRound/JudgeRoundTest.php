<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\RecordedEvent;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\FollowUp;
use Intakt\Core\Review\Contract\JudgedFinding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Judgement;
use Intakt\Core\Review\Contract\Mark;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRound;
use Intakt\Core\Review\UseCase\JudgeRound\JudgeRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\ReviewRounds;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('persistence')]
#[CoversClass(JudgeRoundHandler::class)]
#[CoversClass(JudgeRound::class)]
#[CoversClass(RoundLedger::class)]
#[CoversClass(ReviewRounds::class)]
#[UsesClass(JudgedFindings::class)]
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

        self::assertEquals([new JudgeRound($round->id()->toRfc4122())], $this->fixture->bus->judgings);

        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertCount(1, $this->fixture->bus->judgings);
    }

    public function testARoundStillCollectingIsNotQueuedForJudging(): void
    {
        $this->fixture->start(2);
        $this->fixture->deliver($this->fixture->bus->messages[0], new VerdictPerRole([]));

        self::assertSame([], $this->fixture->bus->judgings);
    }

    public function testJudgingARoundRecordsJudgeDoneWithTheActIdsAndTheFollowUpIds(): void
    {
        $round = $this->completedRound();
        $judgement = new Judgement([new JudgedFinding('A', Mark::Act), new JudgedFinding('B', Mark::Defer), new JudgedFinding('C', Mark::Reject)]);

        $judged = $this->fixture->judge(new ScriptedJudgement($judgement))(new JudgeRound($round->id()->toRfc4122()));

        self::assertSame(['A'], array_map(static fn (Finding $finding): string => $finding->id, $judged->actList));
        self::assertSame(['B'], array_map(static fn (FollowUp $followUp): string => $followUp->finding->id, $judged->followUps));
        self::assertSame([LedgerEventKind::JudgeDone], $this->judgeEvents());
        self::assertSame(['round' => $round->id()->toRfc4122(), 'act' => ['A'], 'follow_ups' => ['B']], json_decode($this->lastPayload(), true));
    }

    public function testTheJudgeIsAskedAboutTheFindingsOfTheRoundWithItsTask(): void
    {
        $round = $this->completedRound();
        $vendor = new ScriptedJudgement(new Judgement([]));

        $this->fixture->judge($vendor)(new JudgeRound($round->id()->toRfc4122()));

        self::assertSame(RoundFixture::TASK, $vendor->asked?->task);
        self::assertSame(['A', 'B', 'C'], array_map(static fn (Finding $finding): string => $finding->id, $vendor->asked->findings ?? []));
    }

    public function testAnInvalidJudgeAnswerLeavesEveryBlockingFindingOnTheActListAndMakesNoFollowUp(): void
    {
        $round = $this->completedRound();
        $vendor = new ScriptedJudgement(Judgement::everyBlockingFindingActs([$this->finding('A', Severity::P1), $this->finding('B', Severity::P2), $this->finding('C', Severity::P3)]));

        $judged = $this->fixture->judge($vendor)(new JudgeRound($round->id()->toRfc4122()));

        self::assertSame(['A', 'B'], array_map(static fn (Finding $finding): string => $finding->id, $judged->actList));
        self::assertSame([], $judged->followUps);
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

    /**
     * @return list<LedgerEventKind>
     */
    private function judgeEvents(): array
    {
        return array_values(array_filter($this->fixture->ledgerKinds(), static fn (LedgerEventKind $kind): bool => LedgerEventKind::JudgeDone === $kind));
    }

    private function lastPayload(): string
    {
        $events = array_values(array_filter(iterator_to_array($this->fixture->ledger()->readInOrder()), static fn (RecordedEvent $event): bool => LedgerEventKind::JudgeDone === $event->event->kind));

        return $events[0]->event->payload;
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }
}
