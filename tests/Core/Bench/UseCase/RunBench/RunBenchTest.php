<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\UseCase\RunBench;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Contract\RepeatedChange;
use Intakt\Core\Bench\Domain\BenchChange;
use Intakt\Core\Bench\Domain\ChangeFiles;
use Intakt\Core\Bench\Domain\DefectMatcher;
use Intakt\Core\Bench\Domain\Expectations;
use Intakt\Core\Bench\Domain\PlantedDefect;
use Intakt\Core\Bench\Domain\RunClassifier;
use Intakt\Core\Bench\Domain\RunOutcome;
use Intakt\Core\Bench\Domain\RunVerdict;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\BenchChangeCheckout;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReadBenchProvenance;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReviewBenchChange;
use Intakt\Core\Bench\UseCase\RunBench\BenchResultFile;
use Intakt\Core\Bench\UseCase\RunBench\RunBench;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\FailureInWords;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\ModelNames;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerOutcome;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\OnePerPlace;
use Intakt\Core\Review\Infrastructure\HashedReviewPrompts;
use Intakt\Core\Review\UseCase\JudgeRound\FailedJudgings;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\ReviewChange;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\RunReviewRound;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\Vendor\Contract\Vendor;
use Intakt\Tests\Core\Bench\UseCase\ReviewBenchChange\UsageRecordingVendor;
use Intakt\Tests\Core\Review\UseCase\ReviewChange\WorkerOnSleep;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\VerdictPerRole;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

/**
 * @phpstan-import-type ResultDocument from BenchResultFile
 * @phpstan-import-type WrittenChange from BenchResultFile
 * @phpstan-import-type WrittenRun from BenchResultFile
 */
#[Group('persistence')]
#[CoversClass(RunBench::class)]
#[CoversClass(BenchResultFile::class)]
#[UsesClass(RepeatedChange::class)]
#[UsesClass(BenchChangeCheckout::class)]
#[UsesClass(ReviewBenchChange::class)]
#[UsesClass(ReadBenchProvenance::class)]
#[UsesClass(HashedReviewPrompts::class)]
#[UsesClass(ReviewChange::class)]
#[UsesClass(ChangeReview::class)]
#[UsesClass(BenchChange::class)]
#[UsesClass(BenchReview::class)]
#[UsesClass(BenchReviewerResult::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(ModelNames::class)]
#[UsesClass(RoundLedgerReading::class)]
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
final class RunBenchTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__.'/../../../../../fixtures/sample-repository';

    private RoundFixture $fixture;

    private string $results;

    public function testTheResultFileOfAScriptedRunHoldsEachReviewersMatchedDefectsTokensAndDuration(): void
    {
        $changes = $this->scriptedRun()['changes'];

        $freeShipping = $this->runOf($changes, 'free-shipping');
        [$first, $second] = $freeShipping['reviewers'];
        self::assertSame(['free-shipping-boundary'], $first['caught']);
        self::assertSame([['id' => 'free-shipping-boundary', 'cause' => 'reviewer']], $second['missed']);
        self::assertSame([['calls' => 1, 'prompt' => 500, 'completion' => 10], 3000], [array_intersect_key($first['tokens'], ['calls' => 0, 'prompt' => 0, 'completion' => 0]), $first['milliseconds']]);
        self::assertSame([['calls' => 1, 'prompt' => 200, 'completion' => 10], 1000], [array_intersect_key($second['tokens'], ['calls' => 0, 'prompt' => 0, 'completion' => 0]), $second['milliseconds']]);
        self::assertSame(['src/shipping.js:3'], array_map(static fn (array $finding): string => $finding['file'].':'.$finding['line'], $freeShipping['act_list']));
        self::assertSame('changes', $freeShipping['decision']);
    }

    public function testTheResultFileNamesTheModelEachReviewerOfAScriptedRunRanOn(): void
    {
        $changes = $this->scriptedRun()['changes'];

        self::assertSame([['big'], ['big']], array_column($this->runOf($changes, 'free-shipping')['reviewers'], 'models'));
    }

    public function testTheSameFindingOnADefectFreeChangeIsAFalseAlarmInTheResultFile(): void
    {
        $changes = $this->scriptedRun()['changes'];

        self::assertSame(['F1'], array_column($this->runOf($changes, 'round-total')['reviewers'][0]['false_alarms'], 'id'));
    }

    public function testAScriptedRunReviewsEveryBenchChangeOnce(): void
    {
        $changes = $this->scriptedRun()['changes'];

        self::assertSame(['bulk-discount-docs', 'coupon-code', 'export-orders', 'free-shipping', 'price-list', 'readme-test-command', 'round-total', 'tax-helper'], array_column($changes, 'change'));
        self::assertSame(array_fill(0, 8, 1), array_map(static fn (array $change): int => count($change['runs']), $changes));
    }

    public function testRepeatedScriptedRunsWithTheSameAnswersAgreeOnEveryChange(): void
    {
        $changes = $this->scriptedRun(2, [$this->findsTheBoundary()])['changes'];

        self::assertSame(array_fill(0, 8, true), array_column($changes, 'agreed'));
    }

    public function testRepeatedScriptedRunsWhereOnlyOneCatchesTheDefectDoNotAgree(): void
    {
        $changes = $this->scriptedRun(2, [$this->findsTheBoundary(), new VerdictPerRole([])])['changes'];

        $freeShipping = array_find($changes, static fn (array $change): bool => 'free-shipping' === $change['change']) ?? self::fail('free-shipping is not in the result file');
        self::assertFalse($freeShipping['agreed']);
        self::assertSame([['free-shipping-boundary'], []], array_map(static fn (array $run): array => $run['reviewers'][0]['caught'], $freeShipping['runs']));
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->results = sys_get_temp_dir().'/intakt-bench-scripted-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->results, $this->results.'-checkouts']);
        parent::tearDown();
    }

    /**
     * @param positive-int         $repeat
     * @param list<VerdictPerRole> $answersPerRound
     *
     * @return ResultDocument
     */
    private function scriptedRun(int $repeat = 1, array $answersPerRound = []): array
    {
        $byRole = ['Look at part 1.' => ['seconds' => 3, 'tokens' => 500], 'Look at part 2.' => ['seconds' => 1, 'tokens' => 200]];
        $vendors = array_map(fn (VerdictPerRole $verdicts): Vendor => new UsageRecordingVendor($verdicts, $this->fixture->ledger(), $this->fixture->clock, $byRole), [] === $answersPerRound ? [$this->findsTheBoundary()] : $answersPerRound);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn($this->panel());
        $run = new RunBench(new BenchChangeCheckout($panels, self::FIXTURES), new ReviewBenchChange($this->reviewChange(new VendorPerRound($vendors)), new RunClassifier(new DefectMatcher())), $this->results.'-checkouts');
        $file = new BenchResultFile(new ReadBenchProvenance(new HashedReviewPrompts(), self::FIXTURES), $this->fixture->clock, $this->results);

        /** @var ResultDocument $decoded */
        $decoded = json_decode(new Filesystem()->readFile($file->write('alpha', $run->reviewEveryChange('alpha', $repeat))), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function findsTheBoundary(): VerdictPerRole
    {
        return new VerdictPerRole(['Look at part 1.' => new Verdict(Decision::Changes, [new Finding('F1', Severity::P1, 'src/shipping.js', 3, null, 'Off by one.', FixableBy::Builder, false, null, null)], [], [])]);
    }

    /**
     * @param list<WrittenChange> $changes
     *
     * @return WrittenRun
     */
    private function runOf(array $changes, string $name): array
    {
        $change = array_find($changes, static fn (array $change): bool => $name === $change['change']) ?? self::fail($name.' is not in the result file');

        return $change['runs'][0];
    }

    private function panel(): Panel
    {
        $members = array_map(
            static fn (int $number): PanelReviewer => new PanelReviewer(new ReviewerRole('role-'.$number, 'Look at part '.$number.'.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
            [1, 2],
        );

        return new Panel($members, QuorumPolicy::All);
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
}
