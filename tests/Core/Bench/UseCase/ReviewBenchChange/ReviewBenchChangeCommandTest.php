<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\UseCase\ReviewBenchChange;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Bench\Contract\BenchProvenance;
use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Domain\BenchChange;
use Intakt\Core\Bench\Domain\ChangeFiles;
use Intakt\Core\Bench\Domain\DefectMatcher;
use Intakt\Core\Bench\Domain\Expectations;
use Intakt\Core\Bench\Domain\PlantedDefect;
use Intakt\Core\Bench\Domain\RunClassifier;
use Intakt\Core\Bench\Domain\RunOutcome;
use Intakt\Core\Bench\Domain\RunVerdict;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\BenchChangeCheckout;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\BenchChangeNotFound;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReadBenchProvenance;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReviewBenchChange;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReviewBenchChangeCommand;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Review\Contract\BlockReasons;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Labels;
use Intakt\Core\Review\Contract\ModelNames;
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
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ReviewBenchChangeCommand::class)]
#[UsesClass(ReviewBenchChange::class)]
#[UsesClass(BenchChangeCheckout::class)]
#[UsesClass(BenchChangeNotFound::class)]
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
#[UsesClass(ModelNames::class)]
#[UsesClass(RoundTokens::class)]
#[UsesClass(Cost::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(BlockReasons::class)]
#[UsesClass(Labels::class)]
#[UsesClass(ChangeReviewRequest::class)]
#[UsesClass(RequestedRound::class)]
#[UsesClass(ReviewTerms::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ReviewerRole::class)]
final class ReviewBenchChangeCommandTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../../fixtures/sample-repository';

    private string $workspace;

    private string|false $temporary;

    public function testTheCommandPrintsTheProvenanceAndEachReviewersFigures(): void
    {
        $tester = $this->runCommand(['change' => 'free-shipping', '--project' => 'alpha']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('change: free-shipping', $display);
        self::assertStringContainsString('reviewer prompt: '.hash('sha256', ReviewerPrompt::INSTRUCTIONS), $display);
        self::assertStringContainsString('judge prompt: '.hash('sha256', JudgePrompt::INSTRUCTIONS), $display);
        self::assertStringContainsString("reviewer role-1 (standard)\n  caught: free-shipping-boundary\n  missed: \n  false alarms: 0\n  harness failure: -\n  tokens: 500 in, 40 out, 1 calls\n  time: 3000 ms", $display);
        self::assertStringContainsString('act: F1 src/shipping.js:', $display);
    }

    public function testTheChangeBuiltForTheRunIsRemovedAfterwards(): void
    {
        $this->runCommand(['change' => 'free-shipping', '--project' => 'alpha']);

        self::assertSame([], glob($this->workspace.'/*'));
    }

    public function testAProjectIsRequired(): void
    {
        $tester = $this->runCommand(['change' => 'free-shipping']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--project is required', $tester->getDisplay());
    }

    public function testAnUnknownChangeIsReportedAndNothingIsLeftBehind(): void
    {
        $tester = $this->runCommand(['change' => 'no-such-change', '--project' => 'alpha']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('There is no bench change named "no-such-change".', $tester->getDisplay());
        self::assertSame([], glob($this->workspace.'/*'));
    }

    // The temporary folder is pointed at an empty one, because the command builds its checkout there and the test must see what it leaves.
    protected function setUp(): void
    {
        $this->temporary = getenv('TMPDIR');
        $this->workspace = sys_get_temp_dir().'/intakt-bench-command-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->workspace);
        putenv('TMPDIR='.$this->workspace);
    }

    protected function tearDown(): void
    {
        putenv(false === $this->temporary ? 'TMPDIR' : 'TMPDIR='.$this->temporary);
        new Filesystem()->remove($this->workspace);
    }

    /**
     * @param array<string, string> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $panel = new Panel([new PanelReviewer(new ReviewerRole('role-1', 'Look.'), new ReviewerModel(Tier::Standard, Provider::Anthropic), 1)], QuorumPolicy::All);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn($panel);
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturn($this->review());
        $command = new ReviewBenchChangeCommand(
            new BenchChangeCheckout($panels, self::FIXTURES),
            new ReviewBenchChange($reviews, new RunClassifier(new DefectMatcher())),
            new ReadBenchProvenance(new HashedReviewPrompts(), self::FIXTURES),
        );
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    private function review(): ChangeReview
    {
        $finding = new Finding('F1', Severity::P1, 'src/shipping.js', $this->plantedLine(), null, 'Off by one.', FixableBy::Builder, false, null, null);
        $reviewer = new ReviewerOutcome('role-1', Tier::Standard, new Verdict(Decision::Changes, [$finding], [], []), null, new RoundTokens(1, 500, 0, 0, 0, 40, 540), 3000, new ModelNames());

        return new ChangeReview('round', 1, Decision::Changes, [], [$finding], new RoundTokens(1, 500, 0, 0, 0, 40, 540), Cost::none(), new BlockReasons(), reviewers: [$reviewer]);
    }

    private function plantedLine(): int
    {
        return Expectations::fromJson(new Filesystem()->readFile(self::FIXTURES.'/bench/free-shipping/expectations.json'))->defects[0]->from;
    }
}
