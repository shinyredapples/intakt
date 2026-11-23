<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\UseCase\RunBench;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Contract\RepeatedChange;
use Intakt\Core\Bench\Domain\BenchChange;
use Intakt\Core\Bench\Domain\BenchFigures;
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
use Intakt\Core\Bench\UseCase\RunBench\BenchReport;
use Intakt\Core\Bench\UseCase\RunBench\BenchResultFile;
use Intakt\Core\Bench\UseCase\RunBench\RunBench;
use Intakt\Core\Bench\UseCase\RunBench\RunBenchCommand;
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;

// Only the vendor is scripted, because the figures the command prints must come from stored rounds and ledger rows, as on an install.
#[Group('persistence')]
#[CoversClass(RunBenchCommand::class)]
#[CoversClass(BenchReport::class)]
#[UsesClass(RunBench::class)]
#[UsesClass(BenchResultFile::class)]
#[UsesClass(BenchFigures::class)]
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
final class BenchCommandOnScriptedVendorTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__.'/../../../../../fixtures/sample-repository';

    private const string CORRECTNESS = 'Check that the change does what the task asks.';

    private const string SECURITY = 'Look for ways the change lets input run commands.';

    private RoundFixture $fixture;

    private string $results;

    public function testTheDocumentedCommandOnTheScriptedVendorSucceedsAndPrintsItsResultFile(): void
    {
        $tester = $this->runTheDocumentedCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $files = glob($this->results.'/*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);
        self::assertStringContainsString('result file: '.$files[0], $tester->getDisplay());
    }

    public function testARepeatOfThreeOnATwoReviewerPanelTotalsEachReviewerOver24RoundsAnd24Calls(): void
    {
        $display = $this->runTheDocumentedCommand()->getDisplay();

        self::assertStringContainsString(
            "totals per reviewer and model over every run:\n"
            ."  correctness (strong, model big), measured over 24 rounds: 3 of 15 planted defects found, 9 false alarms, 0 harness failures left out, 12240 tokens (12000 prompt, 240 completion, 24 calls)\n"
            ."  security (standard, model big), measured over 24 rounds: 0 of 15 planted defects found, 0 false alarms, 0 harness failures left out, 5040 tokens (4800 prompt, 240 completion, 24 calls)\n",
            $display,
        );
    }

    public function testEveryReviewOfTheRunIsARoundStoredOnTheDatabase(): void
    {
        $this->runTheDocumentedCommand();

        self::assertCount(24, $this->fixture->storedRounds());
    }

    protected function setUp(): void
    {
        $this->fixture = new RoundFixture(self::getContainer());
        $this->results = sys_get_temp_dir().'/intakt-bench-command-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->results, $this->results.'-checkouts']);
        parent::tearDown();
    }

    private function runTheDocumentedCommand(): CommandTester
    {
        $byRole = [self::CORRECTNESS => ['seconds' => 3, 'tokens' => 500], self::SECURITY => ['seconds' => 1, 'tokens' => 200]];
        $vendor = new UsageRecordingVendor($this->correctnessFindsTheBoundary(), $this->fixture->ledger(), $this->fixture->clock, $byRole);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn($this->demoPanel());
        $command = new RunBenchCommand(
            new RunBench(new BenchChangeCheckout($panels, self::FIXTURES), new ReviewBenchChange($this->reviewChange($vendor), new RunClassifier(new DefectMatcher())), $this->results.'-checkouts'),
            new BenchReport(),
            new BenchResultFile(new ReadBenchProvenance(new HashedReviewPrompts(), self::FIXTURES), $this->fixture->clock, $this->results),
        );
        $tester = new CommandTester($command);
        $tester->execute(['--project' => 'sample', '--repeat' => '3']);

        return $tester;
    }

    private function correctnessFindsTheBoundary(): VerdictPerRole
    {
        $planted = Expectations::fromJson(new Filesystem()->readFile(self::FIXTURES.'/bench/free-shipping/expectations.json'))->defects[0];

        return new VerdictPerRole([self::CORRECTNESS => new Verdict(Decision::Changes, [new Finding('F1', Severity::P1, $planted->file, $planted->from, null, 'Off by one.', FixableBy::Builder, false, null, null)], [], [])]);
    }

    // The demo project's roles and tiers, so the reviewers are named here as in the README's table.
    private function demoPanel(): Panel
    {
        return new Panel([
            new PanelReviewer(new ReviewerRole('correctness', self::CORRECTNESS), new ReviewerModel(Tier::Strong, Provider::Anthropic), 1),
            new PanelReviewer(new ReviewerRole('security', self::SECURITY), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1),
        ], QuorumPolicy::All);
    }

    // The reviews and the judge run when the review waits, because no worker process runs beside a test.
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
