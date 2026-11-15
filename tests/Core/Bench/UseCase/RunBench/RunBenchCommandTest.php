<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\UseCase\RunBench;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Bench\Contract\BenchProvenance;
use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Contract\RunVerdict;
use Intakt\Core\Bench\Domain\BenchChange;
use Intakt\Core\Bench\Domain\ChangeFiles;
use Intakt\Core\Bench\Domain\DefectMatcher;
use Intakt\Core\Bench\Domain\Expectations;
use Intakt\Core\Bench\Domain\PlantedDefect;
use Intakt\Core\Bench\Domain\RunClassifier;
use Intakt\Core\Bench\Domain\RunOutcome;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\BenchChangeCheckout;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReadBenchProvenance;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReviewBenchChange;
use Intakt\Core\Bench\UseCase\RunBench\BenchResultFile;
use Intakt\Core\Bench\UseCase\RunBench\RunBench;
use Intakt\Core\Bench\UseCase\RunBench\RunBenchCommand;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\PromptHashes;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RequestedRound;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerOutcome;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\RoundTierNotMapped;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Infrastructure\HashedReviewPrompts;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @phpstan-import-type ResultDocument from BenchResultFile
 */
#[CoversClass(RunBenchCommand::class)]
#[CoversClass(RunBench::class)]
#[CoversClass(BenchChangeCheckout::class)]
#[UsesClass(BenchResultFile::class)]
#[UsesClass(ReviewBenchChange::class)]
#[UsesClass(ReadBenchProvenance::class)]
#[UsesClass(BenchProvenance::class)]
#[UsesClass(HashedReviewPrompts::class)]
#[UsesClass(PromptHashes::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(JudgePrompt::class)]
#[UsesClass(BenchChange::class)]
#[UsesClass(BenchReview::class)]
#[UsesClass(BenchReviewerResult::class)]
#[UsesClass(RunClassifier::class)]
#[UsesClass(DefectMatcher::class)]
#[UsesClass(Expectations::class)]
#[UsesClass(PlantedDefect::class)]
#[UsesClass(ChangeFiles::class)]
#[UsesClass(RunOutcome::class)]
#[UsesClass(RunVerdict::class)]
#[UsesClass(ChangeReview::class)]
#[UsesClass(ReviewerOutcome::class)]
#[UsesClass(RoundTokens::class)]
#[UsesClass(Cost::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(ChangeReviewRequest::class)]
#[UsesClass(RequestedRound::class)]
#[UsesClass(ReviewTerms::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(RoundTierNotMapped::class)]
final class RunBenchCommandTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../../fixtures/sample-repository';

    private string $workspace;

    private string $results;

    // The checkouts go to a folder of the test, so it can see what the run leaves behind.
    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir().'/intakt-bench-run-'.bin2hex(random_bytes(4));
        $this->results = $this->workspace.'-results';
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->workspace, $this->results]);
    }

    public function testTheBenchCommandWithoutAProjectFailsAndWritesNoFile(): void
    {
        $tester = $this->runCommand([], $this->reviews());

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--project is required', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($this->results);
    }

    public function testTheBenchCommandWritesOneJsonResultFileAndPrintsItsPath(): void
    {
        $tester = $this->runCommand(['--project' => 'alpha'], $this->reviews());

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $files = $this->resultFiles();
        self::assertCount(1, $files);
        self::assertStringContainsString('result file: '.$files[0], $tester->getDisplay());
    }

    public function testABenchRunReviewsEveryBenchChangeOnceInNameOrder(): void
    {
        $this->runCommand(['--project' => 'alpha'], $this->reviews());

        /** @var ResultDocument $result */
        $result = json_decode(new Filesystem()->readFile($this->resultFiles()[0] ?? self::fail('no result file')), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['bulk-discount-docs', 'coupon-code', 'export-orders', 'free-shipping', 'price-list', 'readme-test-command', 'round-total', 'tax-helper'], array_column($result['changes'], 'change'));
    }

    public function testEveryPrintedFigureIsLabeledAsMeasured(): void
    {
        $display = $this->runCommand(['--project' => 'alpha'], $this->reviews())->getDisplay();

        self::assertStringContainsString("free-shipping: decision changes, measured 1 act list findings\n  role-1 (standard), measured: 1 caught, 0 missed, 0 false alarms, 500 prompt tokens, 40 completion tokens, 1 calls, 3000 ms\n", $display);
        $unlabeled = array_filter(explode("\n", $display), static fn (string $line): bool => 1 === preg_match('/\d/', $line) && !str_contains($line, 'measured') && !str_starts_with($line, 'result file: '));
        self::assertSame([], array_values($unlabeled));
    }

    public function testAFailedReviewEndsTheRunWithoutAFileAndRemovesItsCheckout(): void
    {
        $reviews = self::createStub(ChangeReviews::class);
        $during = 0;
        $reviews->method('review')->willReturnCallback(function () use (&$during): never {
            $during = \count(glob($this->workspace.'/*') ?: []);

            throw new RoundTierNotMapped('alpha', Tier::Standard);
        });

        $tester = $this->runCommand(['--project' => 'alpha'], $reviews);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('maps no model to the "standard" tier', $tester->getDisplay());
        self::assertSame(1, $during);
        self::assertSame([], glob($this->workspace.'/*'));
        self::assertDirectoryDoesNotExist($this->results);
    }

    public function testEveryCheckoutIsRemovedAfterASuccessfulRun(): void
    {
        $during = [];
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturnCallback(function () use (&$during): ChangeReview {
            $during[] = \count(glob($this->workspace.'/*') ?: []);

            return $this->reviews()->review(...\func_get_args());
        });

        $this->runCommand(['--project' => 'alpha'], $reviews);

        self::assertSame(array_fill(0, 8, 1), $during);
        self::assertSame([], glob($this->workspace.'/*'));
    }

    /**
     * @return list<string>
     */
    private function resultFiles(): array
    {
        $files = glob($this->results.'/*.json');

        return false === $files ? [] : $files;
    }

    /**
     * @param array<string, string> $input
     */
    private function runCommand(array $input, ChangeReviews $reviews): CommandTester
    {
        $panel = new Panel([new PanelReviewer(new ReviewerRole('role-1', 'Look.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1)], QuorumPolicy::All);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn($panel);
        $command = new RunBenchCommand(
            new RunBench(new BenchChangeCheckout($panels, self::FIXTURES), new ReviewBenchChange($reviews, new RunClassifier(new DefectMatcher())), $this->workspace),
            new BenchResultFile(new ReadBenchProvenance(new HashedReviewPrompts(), self::FIXTURES), new MockClock('2026-11-15 10:30:00'), $this->results),
        );
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function reviews(): ChangeReviews
    {
        $finding = new Finding('F1', Severity::P1, 'src/shipping.js', $this->plantedLine(), null, 'Off by one.', FixableBy::Builder, false, null, null);
        $reviewer = new ReviewerOutcome('role-1', Tier::Standard, new Verdict(Decision::Changes, [$finding], [], []), null, new RoundTokens(1, 500, 0, 0, 0, 40, 540), 3000);
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturn(new ChangeReview('round', 1, Decision::Changes, [], [$finding], new RoundTokens(1, 500, 0, 0, 0, 40, 540), Cost::none(), [], reviewers: [$reviewer]));

        return $reviews;
    }

    private function plantedLine(): int
    {
        return Expectations::fromJson(new Filesystem()->readFile(self::FIXTURES.'/bench/free-shipping/expectations.json'))->defects[0]->from;
    }
}
