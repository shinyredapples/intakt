<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\UseCase\ReviewBenchChange;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Domain\BenchChange;
use Intakt\Core\Bench\Domain\ChangeFiles;
use Intakt\Core\Bench\Domain\DefectMatcher;
use Intakt\Core\Bench\Domain\Expectations;
use Intakt\Core\Bench\Domain\FailureCause;
use Intakt\Core\Bench\Domain\PlantedDefect;
use Intakt\Core\Bench\Domain\RunClassifier;
use Intakt\Core\Bench\Domain\RunOutcome;
use Intakt\Core\Bench\Domain\RunVerdict;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReviewBenchChange;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ModelNames;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerOutcome;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\OnePerPlace;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Core\Review\SampleIdentity;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\StoppingAtTheLimitForRole;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

#[Group('persistence')]
#[CoversClass(ReviewBenchChange::class)]
#[CoversClass(BenchChange::class)]
#[CoversClass(BenchReview::class)]
#[CoversClass(BenchReviewerResult::class)]
#[CoversClass(ReviewerOutcome::class)]
#[UsesClass(ModelNames::class)]
#[CoversClass(RoundLedgerReading::class)]
#[UsesClass(ReviewChange::class)]
#[UsesClass(ChangeReview::class)]
#[UsesClass(RunClassifier::class)]
#[UsesClass(DefectMatcher::class)]
#[UsesClass(Expectations::class)]
#[UsesClass(PlantedDefect::class)]
#[UsesClass(ChangeFiles::class)]
#[UsesClass(RunOutcome::class)]
#[UsesClass(RunVerdict::class)]
#[UsesClass(StoredVerdicts::class)]
#[UsesClass(OnePerPlace::class)]
#[UsesClass(ChangeDecision::class)]
#[UsesClass(FailureInWords::class)]
#[UsesClass(Cost::class)]
#[UsesClass(ConfiguredPrices::class)]
#[UsesClass(FailedJudgings::class)]
#[UsesClass(RoundTokens::class)]
final class ReviewBenchChangeTest extends KernelTestCase
{
    private RoundFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
    }

    public function testTheReviewReturnsEachReviewersMatchedDefectsTokensDurationAndTheActList(): void
    {
        $verdicts = new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [$this->findingAt('A', 5)], [], [])]);
        $review = $this->review($this->defectAt(5), $this->recording($verdicts));

        [$first, $second] = $review->reviewers;
        self::assertSame(['coupon'], $first->verdict->caught);
        self::assertSame([], $first->verdict->missed);
        self::assertSame(['coupon' => FailureCause::Reviewer], $second->verdict->missed);
        self::assertSame([1, 500, 3000], [$first->tokens->calls, $first->tokens->prompt, $first->milliseconds]);
        self::assertSame([1, 200, 1000], [$second->tokens->calls, $second->tokens->prompt, $second->milliseconds]);
        self::assertSame(['src/A.php:5'], array_map(static fn (Finding $finding): string => $finding->file.':'.$finding->line, $review->actList));
        self::assertSame(Decision::Changes, $review->decision);
    }

    public function testAFindingOutsideEveryPlantedRangeIsAFalseAlarmOfTheReviewerThatReportedIt(): void
    {
        $verdicts = new VerdictPerRole(['Look at part 2.' => new Verdict(Decision::Changes, [$this->findingAt('B', 9)], [], [])]);
        $review = $this->review(Expectations::fromJson('[]'), $this->recording($verdicts));

        [$first, $second] = $review->reviewers;
        self::assertSame([], $first->verdict->falseAlarms);
        self::assertSame(['B'], array_map(static fn (Finding $finding): string => $finding->id, $second->verdict->falseAlarms));
    }

    public function testAReviewerThatFailsIsAHarnessFailureAndNotMissedDefects(): void
    {
        $review = $this->review($this->defectAt(5), new StoppingAtTheLimitForRole('Look at part 1.'));

        [$failed, $other] = $review->reviewers;
        self::assertSame('stopped at its output limit', $failed->verdict->harnessFailure);
        self::assertSame(['coupon' => FailureCause::Harness], $failed->verdict->missed);
        self::assertNull($other->verdict->harnessFailure);
    }

    private function recording(VerdictPerRole $verdicts): Vendor
    {
        $byRole = ['Look at part 1.' => ['seconds' => 3, 'tokens' => 500], 'Look at part 2.' => ['seconds' => 1, 'tokens' => 200]];

        return new UsageRecordingVendor($verdicts, $this->fixture->ledger(), $this->fixture->clock, $byRole);
    }

    private function defectAt(int $line): Expectations
    {
        return Expectations::fromJson(json_encode([['id' => 'coupon', 'file' => 'src/A.php', 'lines' => ['from' => $line, 'to' => $line], 'description' => 'A planted defect.', 'severity' => 'P1']], JSON_THROW_ON_ERROR));
    }

    private function review(Expectations $expectations, Vendor $vendor): BenchReview
    {
        $panel = $this->panel();
        $request = new ChangeReviewRequest('alpha', __DIR__, RoundFixture::TASK, RoundFixture::DIFF, new RequestedRound(RoundFixture::CHANGE), SampleIdentity::of(RoundFixture::TASK, $panel), $panel);
        $change = new BenchChange('coupon-code', $expectations, new ChangeFiles(['src/A.php' => 20]), $request);

        return new ReviewBenchChange($this->reviewChange($vendor), new RunClassifier(new DefectMatcher()))->of($change);
    }

    private function reviewChange(Vendor $vendor): ReviewChange
    {
        $delivered = 0;
        $work = function () use ($vendor, &$delivered): void {
            foreach (array_slice($this->fixture->bus->messages, $delivered) as $message) {
                $this->fixture->deliver($message, $vendor);
                ++$delivered;
            }

            foreach ($this->fixture->judgings() as $judging) {
                $this->fixture->judge($vendor)($judging);
            }
        };
        $serializer = self::getContainer()->get('serializer.stored');
        $handler = $this->fixture->startHandlerOn($this->fixture->bus);

        return new ReviewChange(
            new MessageBus([new HandleMessageMiddleware(new HandlersLocator([RunReviewRound::class => [$handler]]))]),
            $this->fixture->transactions(),
            new StoredFindings($this->fixture->entities, $serializer, $this->fixture->machine('state_machine.review_round')),
            new RoundLedgerReading($this->fixture->ledger(), $this->fixture->ledgerPayloads(), new ConfiguredPrices([])),
            new ChangeDecision(),
            new StoredVerdicts($serializer),
            new WorkerOnSleep($this->fixture->clock, $work),
            new FailureInWords(),
            $vendor,
            $this->fixture->noFailedJudgings(),
        );
    }

    private function panel(): Panel
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            [1, 2],
        );

        return new Panel($members, QuorumPolicy::All);
    }

    private function findingAt(string $id, int $line): Finding
    {
        return new Finding($id, Severity::P1, 'src/A.php', $line, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }
}
