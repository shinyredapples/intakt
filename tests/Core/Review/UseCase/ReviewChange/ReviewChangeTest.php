<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\UseCase\ReviewChange\ChangeDecision;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\RoundLedger;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRoundHandler;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

#[Group('persistence')]
#[CoversClass(ReviewChange::class)]
#[CoversClass(RoundLedgerReading::class)]
#[CoversClass(ChangeReview::class)]
#[CoversClass(RoundNotNext::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(ChangeReviewRequest::class)]
final class ReviewChangeTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testATwoReviewerRoundWhereBothApproveIsApproved(): void
    {
        $review = $this->reviewWith(new VerdictPerRole([]));

        self::assertSame(Decision::Approve, $review->decision);
        self::assertSame(1, $review->round);
        self::assertSame([Decision::Approve, Decision::Approve], array_map(static fn ($vote): Decision => $vote->verdict->verdict, $review->verdicts));
    }

    public function testARoundWithAChangesVerdictBelowQuorumAsksForChanges(): void
    {
        $review = $this->reviewWith(new VerdictPerRole(['Look at part 2.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], [])]));

        self::assertSame(Decision::Changes, $review->decision);
    }

    public function testARoundWhoseReviewerIsBlockedIsBlocked(): void
    {
        $review = $this->reviewWith(new VerdictPerRole(['Look at part 2.' => new Verdict(Decision::Blocked, [], [], [])]));

        self::assertSame(Decision::Blocked, $review->decision);
    }

    public function testTheActListHoldsOnlyTheFindingsTheJudgeMarkedAct(): void
    {
        $verdict = new Verdict(Decision::Changes, [$this->finding('A', Severity::P1), $this->finding('B', Severity::P3)], [], []);

        $review = $this->reviewWith(new VerdictPerRole(['Look at part 1.' => $verdict]));

        self::assertSame(['F1'], array_map(static fn (Finding $finding): string => $finding->id, $review->actList));
    }

    public function testARoundNoWorkerFinishesIsBlockedAtTheCeilingWithTheReason(): void
    {
        $review = $this->reviewChange(static function (): void {}, 5)->review($this->request());

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame([], $review->actList);
        self::assertStringContainsString('did not finish within 5 seconds', $review->reasons[0]);
    }

    public function testTheTokensOfCallsRecordedAfterTheRoundStartedAreAdded(): void
    {
        $this->recordUsage('before', 1000);
        $review = $this->reviewWith(new VerdictPerRole([]), usageOfCall: 120);

        self::assertSame([1, 100, 20, 120], [$review->tokens->calls, $review->tokens->prompt, $review->tokens->completion, $review->tokens->total]);
    }

    public function testARoundNumberThatIsNotTheNextOneIsRefusedBeforeAnythingStarts(): void
    {
        $this->expectException(RoundNotNext::class);
        $this->expectExceptionMessage('Round 2 was asked for, but the next round of this change is round 1.');

        $this->reviewChange(static function (): void {})->review($this->request(round: 2));
    }

    public function testTheNextRoundNumberIsAccepted(): void
    {
        $this->reviewWith(new VerdictPerRole([]));

        $second = $this->reviewWith(new VerdictPerRole([]), round: 2);

        self::assertSame(2, $second->round);
    }

    private function reviewWith(VerdictPerRole $vendor, ?int $round = null, int $usageOfCall = 0): ChangeReview
    {
        $delivered = 0;
        $work = function () use ($vendor, &$delivered, $usageOfCall): void {
            if (0 < $usageOfCall && 0 === $delivered) {
                $this->recordUsage('during', $usageOfCall);
            }

            foreach (array_slice($this->fixture->bus->messages, $delivered) as $message) {
                $this->fixture->deliver($message, $vendor);
                ++$delivered;
            }

            foreach ($this->fixture->judgings() as $judging) {
                $this->fixture->judge($vendor)($judging);
            }
        };

        return $this->reviewChange($work)->review($this->request($round));
    }

    private function reviewChange(callable $work, int $ceiling = ReviewChange::WAIT_CEILING_SECONDS): ReviewChange
    {
        $serializer = self::getContainer()->get(SerializerInterface::class);
        assert($serializer instanceof SerializerInterface);
        $handler = new RunReviewRoundHandler($this->fixture->transactions(), new RoundLedger($this->fixture->ledger()), $this->fixture->bus);
        $findings = new StoredFindings($this->fixture->entities, $serializer, $this->fixture->machine('state_machine.review_round'));

        return new ReviewChange(
            new HandlingBus($handler),
            $this->fixture->transactions(),
            $findings,
            new RoundLedgerReading($this->fixture->ledger()),
            new ChangeDecision(),
            $serializer,
            new WorkerOnSleep($this->fixture->clock, $work(...)),
            $ceiling,
        );
    }

    private function request(?int $round = null): ChangeReviewRequest
    {
        $panel = $this->panel();

        return new ChangeReviewRequest('alpha', __DIR__, RoundFixture::TASK, RoundFixture::CHANGE, SampleIdentity::of(RoundFixture::TASK, $panel), $panel, $round);
    }

    // The roles and prompts RoundFixture's workers answer by, so a scripted verdict reaches the reviewer it names.
    private function panel(): Panel
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            [1, 2],
        );

        return new Panel($members, QuorumPolicy::All);
    }

    private function recordUsage(string $call, int $total): void
    {
        $usage = ['call' => $call, 'phase' => 'settled', 'project' => 'alpha', 'usages' => [['prompt' => $total - $total / 6, 'completion' => $total / 6, 'total' => $total]]];
        $appended = $this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::UsageRecorded, json_encode($usage, JSON_THROW_ON_ERROR)));
        self::assertTrue($appended->succeeded());
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }
}
