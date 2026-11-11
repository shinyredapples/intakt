<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\IgnoredProgress;
use Intakt\Core\Review\Contract\JudgeRequest;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\RoundProgress;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Serializer\SerializerInterface;

#[Group('persistence')]
#[CoversClass(ReviewChange::class)]
#[CoversClass(RoundLedgerReading::class)]
#[CoversClass(ChangeReview::class)]
#[CoversClass(RoundNotNext::class)]
#[CoversClass(StoredVerdicts::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
#[UsesClass(Cost::class)]
#[UsesClass(ConfiguredPrices::class)]
#[UsesClass(RoundProgress::class)]
#[UsesClass(IgnoredProgress::class)]
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

    public function testAFailedReviewerBesideAnApprovingOneBlocksTheRoundAndIsNamedInPlainWords(): void
    {
        $review = $this->reviewWith(new StoppingAtTheLimitForRole('Look at part 1.'));

        self::assertSame(Decision::Blocked, $review->decision);
        self::assertSame(['role-1 reviewer failed, stopped at its output limit'], $review->reasons);
    }

    public function testAFailedReviewerBesideOneAskingForChangesGivesChangesAndStillNamesTheFailure(): void
    {
        $changes = new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], []);

        $review = $this->reviewWith(new StoppingAtTheLimitForRole('Look at part 1.', ['Look at part 2.' => $changes]));

        self::assertSame(Decision::Changes, $review->decision);
        self::assertSame(['role-1 reviewer failed, stopped at its output limit'], $review->reasons);
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

    public function testEachPollReportsTheProgressOfTheRound(): void
    {
        $progress = new RecordingProgress();

        $this->reviewChange(static function (): void {}, 3)->review($this->request(), $progress);

        // The first read of the round reports too, because the caller should see the start before any wait.
        self::assertCount(4, $progress->reported);
        self::assertEquals(new RoundProgress(0, 2, false), $progress->reported[3]);
    }

    public function testTheLastReportSaysTheRoundIsJudgedWhenTheWorkersFinishIt(): void
    {
        $progress = new RecordingProgress();
        $work = function (): void {
            foreach ($this->fixture->bus->messages as $message) {
                $this->fixture->deliver($message, new VerdictPerRole([]));
            }

            foreach ($this->fixture->judgings() as $judging) {
                $this->fixture->judge(new VerdictPerRole([]))($judging);
            }
        };

        $this->reviewChange($work)->review($this->request(), $progress);

        $last = array_last($progress->reported);
        self::assertInstanceOf(RoundProgress::class, $last);
        self::assertSame([2, 2, true, ['verdict', 'verdict']], [$last->reviewersFinished, $last->reviewers, $last->judged, array_values($last->finished)]);
    }

    public function testTheTokensOfTheCallsMadeForTheRoundAreAddedAndNoOthers(): void
    {
        $this->recordUsage('before', 1000);
        $review = $this->reviewWith(new VerdictPerRole([]), duringTheRound: fn () => $this->recordUsage('during', 120, $this->openRoundId()));

        self::assertSame([1, 100, 20, 120], [$review->tokens->calls, $review->tokens->prompt, $review->tokens->completion, $review->tokens->total]);
    }

    public function testTheTokensOfTheJudgeCallOfTheRoundAreCounted(): void
    {
        $reviewers = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->finding('A', Severity::P1)], [], [])]);
        $vendor = new JudgeCallingVendor($reviewers, fn (JudgeRequest $request) => $this->recordUsage('judge', 300, $request->round->toRfc4122()));

        $review = $this->reviewWith($vendor);

        self::assertSame([1, 250, 50, 300], [$review->tokens->calls, $review->tokens->prompt, $review->tokens->completion, $review->tokens->total]);
    }

    public function testTheCostOfTheCallsMadeForTheRoundComesWithTheReview(): void
    {
        $this->recordUsage('before', 1000);
        $review = $this->reviewWith(new VerdictPerRole([]), duringTheRound: fn () => $this->recordUsage('during', 120, $this->openRoundId()));

        self::assertEqualsWithDelta((100 * 1.0 + 20 * 2.0) / 1_000_000, $review->cost->usd, 1e-12);
    }

    public function testTheTokensOfACallMadeForAnotherRoundAreLeftOut(): void
    {
        $review = $this->reviewWith(new VerdictPerRole([]), duringTheRound: fn () => $this->recordUsage('during', 120, '0192a000-0000-7000-8000-0000000000ff'));

        self::assertSame([0, 0], [$review->tokens->calls, $review->tokens->total]);
    }

    public function testARoundNumberThatIsNotTheNextOneIsRefusedBeforeAnythingStarts(): void
    {
        try {
            $this->reviewChange(static function (): void {})->review($this->request(round: 2));
            self::fail('Round 2 of a change without rounds was started.');
        } catch (RoundNotNext $refused) {
            self::assertStringContainsString('the next round of this change is round 1', $refused->getMessage());
        }

        self::assertSame([], $this->fixture->storedRounds());
    }

    public function testARoundAnotherCallerOpensFirstIsRefusedThoughItWasNextWhenAskedFor(): void
    {
        $bus = new InterveningRoundBus(fn (): ReviewRound => $this->fixture->start(1), $this->commandBus());

        $this->expectException(RoundNotNext::class);

        $this->reviewChange(static function (): void {}, bus: $bus)->review($this->request(round: 1));
    }

    public function testTheNextRoundNumberIsAccepted(): void
    {
        $this->reviewWith(new VerdictPerRole([]));

        $second = $this->reviewWith(new VerdictPerRole([]), round: 2);

        self::assertSame(2, $second->round);
    }

    private function reviewWith(Vendor $vendor, ?int $round = null, ?Closure $duringTheRound = null): ChangeReview
    {
        $delivered = 0;
        $work = function () use ($vendor, &$delivered, $duringTheRound): void {
            if ($duringTheRound instanceof Closure && 0 === $delivered) {
                $duringTheRound();
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

    private function reviewChange(Closure $work, int $ceiling = ReviewChange::WAIT_CEILING_SECONDS, ?MessageBusInterface $bus = null): ReviewChange
    {
        $serializer = $this->serializer();
        $findings = new StoredFindings($this->fixture->entities, $serializer, $this->fixture->machine('state_machine.review_round'));

        return new ReviewChange(
            $bus ?? $this->commandBus(),
            $this->fixture->transactions(),
            $findings,
            new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices(['big' => ['input' => 1.0, 'output' => 2.0, 'cache_read' => 1.0, 'cache_write' => 1.0]])),
            new ChangeDecision(),
            new StoredVerdicts($serializer),
            new WorkerOnSleep($this->fixture->clock, $work),
            new FailureInWords(),
            $ceiling,
        );
    }

    // Messenger's own handling, so a refusal reaches the caller wrapped as the application's command bus wraps it; the reviewers' messages go to the test's workers.
    private function commandBus(): MessageBusInterface
    {
        $handler = $this->fixture->startHandlerOn($this->fixture->bus);

        return new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RunReviewRound::class => [$handler]]))]);
    }

    private function serializer(): SerializerInterface
    {
        return self::getContainer()->get('serializer.stored');
    }

    private function request(?int $round = null): ChangeReviewRequest
    {
        $panel = $this->panel();

        return new ChangeReviewRequest('alpha', __DIR__, RoundFixture::TASK, RoundFixture::DIFF, new RequestedRound(RoundFixture::CHANGE, $round), SampleIdentity::of(RoundFixture::TASK, $panel), $panel);
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

    private function openRoundId(): string
    {
        $round = array_first($this->fixture->storedRounds());
        self::assertInstanceOf(ReviewRound::class, $round);

        return $round->id()->toRfc4122();
    }

    // Both rows UsageLedger writes, because only the started one names the round and only the settled one carries the tokens.
    private function recordUsage(string $call, int $total, ?string $round = null): void
    {
        $started = ['call' => $call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'model' => 'big'];
        $this->append(null === $round ? $started : $started + ['round' => $round]);
        $this->append(['call' => $call, 'phase' => 'settled', 'usages' => [['account' => null, 'model' => null, 'prompt' => $total - intdiv($total, 6), 'completion' => intdiv($total, 6), 'total' => $total]]]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(array $payload): void
    {
        $appended = $this->fixture->ledger()->append(new LedgerEvent(LedgerEventKind::UsageRecorded, json_encode($payload, JSON_THROW_ON_ERROR)));
        self::assertTrue($appended->succeeded());
    }

    private function finding(string $id, Severity $severity): Finding
    {
        return new Finding($id, $severity, null, null, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }
}
