<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Tests\Core\Disk\DiskWithRoom;

use Closure;
use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\BlockReasons;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\ChangeReviewRequest;
use Intakt\Core\Review\Contract\ChangeReviews;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\RoundNotNext;
use Intakt\Core\Review\Contract\RoundTierNotMapped;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeNotFound;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Contract\RepositoryNotFound;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\CleanupOnStop;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\LocalChangeReview;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\OutputFormat;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeCommand;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeInput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewExit;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewOutput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewOutputVerdict;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewReport;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Support\StoredSerializerFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ReviewChangeCommand::class)]
#[CoversClass(ReviewExit::class)]
#[UsesClass(ReviewReport::class)]
#[UsesClass(BlockReasons::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(RepositoryNotFound::class)]
#[UsesClass(CleanupOnStop::class)]
#[UsesClass(LocalChangeReview::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(ReviewChangeInput::class)]
#[UsesClass(OutputFormat::class)]
#[UsesClass(ReviewOutput::class)]
#[UsesClass(ReviewOutputVerdict::class)]
#[UsesClass(RoundTokens::class)]
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(RoundTierNotMapped::class)]
final class ReviewChangeCommandTest extends TestCase
{
    private TemporaryRepository $repository;

    /**
     * @return iterable<string, array{Decision, int}>
     */
    public static function decisions(): iterable
    {
        yield 'approve' => [Decision::Approve, 0];
        yield 'changes' => [Decision::Changes, 10];
        yield 'blocked' => [Decision::Blocked, 11];
    }

    #[DataProvider('decisions')]
    public function testTheExitCodeTellsTheDecisionApart(Decision $decision, int $code): void
    {
        $tester = $this->whenTheCommandRuns($this->reviewing($decision));

        self::assertSame($code, $tester->getStatusCode());
        self::assertNotSame(Command::FAILURE, $tester->getStatusCode());
    }

    // Only the report's arrival here, because ReviewReportTest checks its lines without a git repository.
    public function testTheTextFormatPrintsTheReportsLines(): void
    {
        $tester = $this->whenTheCommandRuns($this->reviewing(Decision::Changes));

        self::assertSame(implode("\n", $this->report()->asLines($this->outcome(Decision::Changes)))."\n", $tester->getDisplay());
    }

    public function testTheJsonFormatPrintsTheReportsJson(): void
    {
        $tester = $this->whenTheCommandRuns($this->reviewing(Decision::Blocked), ['--format' => 'json']);

        self::assertSame($this->report()->asJson($this->outcome(Decision::Blocked))."\n", $tester->getDisplay());
    }

    public function testTheFormatOptionNamesItsValuesOnceInTheHelp(): void
    {
        $application = new Application();
        $application->addCommand(new ReviewChangeCommand(new LocalChangeReview($this->onePanel(), $this->reviewing(Decision::Blocked), new DiskWithRoom()), $this->report()));
        $tester = new CommandTester($application->find('help'));
        $tester->execute(['command_name' => 'intakt:review']);

        self::assertSame(1, substr_count($tester->getDisplay(), 'json'));
    }

    public function testAnUnknownProjectFailsWithTheMessageAndTheFailureCode(): void
    {
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willThrowException(new PanelNotConfigured('nobody'));

        $tester = $this->whenTheCommandRuns($this->reviewing(Decision::Approve), ['--project' => 'nobody'], $panels);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('nobody', $tester->getDisplay());
    }

    public function testARoundTheChangeCannotHaveNextFailsWithTheFailureCode(): void
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willThrowException(RoundNotNext::for(3, 1));

        $tester = $this->whenTheCommandRuns($reviews, ['--round' => '3']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Round 3 was asked for', $tester->getDisplay());
    }

    public function testAProjectWithoutALightTierIsRefusedWithTheProjectAndTheTierAndTheFailureCode(): void
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willThrowException(new RoundTierNotMapped('alpha', Tier::Light));

        $tester = $this->whenTheCommandRuns($reviews);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        // Spaces joined, because the error block wraps the long message.
        self::assertStringContainsString('Project "alpha" maps no model to the "light" tier', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    public function testAReportableFailureFromTheRoundPrintsItsMessageAndTheFailureCode(): void
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willThrowException(new ForgeReadFailed('git cat-file failed: broken object.'));

        $tester = $this->whenTheCommandRuns($reviews);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('git cat-file failed: broken object.', $tester->getDisplay());
    }

    public function testAnInvalidArgumentFromInsideTheRoundIsNotCaught(): void
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willThrowException(new InvalidArgumentException('a programming error'));

        $this->expectExceptionObject(new InvalidArgumentException('a programming error'));

        $this->whenTheCommandRuns($reviews);
    }

    public function testAMissingRepositoryFailsNamingThePath(): void
    {
        $tester = $this->whenTheCommandRuns($this->reviewing(Decision::Approve), ['--repository' => 'no-such-repository']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(RepositoryNotFound::at('no-such-repository')->getMessage(), $tester->getDisplay());
    }

    public function testAnUnknownBranchFailsNamingTheBranch(): void
    {
        $tester = $this->whenTheCommandRuns($this->reviewing(Decision::Approve), ['change' => 'nowhere']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(ChangeNotFound::named(new ChangeId('nowhere'))->getMessage(), $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function stopSignals(): iterable
    {
        yield 'SIGINT' => [ReviewChangeCommand::INTERRUPT, 130];
        yield 'SIGTERM' => [ReviewChangeCommand::TERMINATE, 143];
    }

    #[DataProvider('stopSignals')]
    public function testAStopSignalDuringTheRoundRemovesTheSnapshotAndGivesTheSignalsExitCode(int $signal, int $code): void
    {
        $snapshot = '';
        $exit = null;
        $reviews = self::createStub(ChangeReviews::class);
        $command = new ReviewChangeCommand(new LocalChangeReview($this->onePanel(), $reviews, new DiskWithRoom()), $this->report());
        $reviews->method('review')->willReturnCallback(function (ChangeReviewRequest $request) use ($command, $signal, &$snapshot, &$exit): ChangeReview {
            $snapshot = $request->checkoutRoot;
            $exit = $command->handleSignal($signal);

            return $this->outcome(Decision::Approve);
        });

        $this->whenTheCommandIsRun($command);

        self::assertSame($code, $exit);
        self::assertDirectoryDoesNotExist($snapshot);
        self::assertCount(1, $this->repository->worktrees());
    }

    // One test for a PHP with and without signal support rather than a skipped one, because the tests gate fails on a skip and the CI image has no pcntl.
    public function testItListensForTheStopSignalsOnlyWherePhpCanHandleThem(): void
    {
        $command = new ReviewChangeCommand(new LocalChangeReview($this->onePanel(), $this->reviewing(Decision::Approve), new DiskWithRoom()), $this->report());

        $expected = SignalRegistry::isSupported() ? [constant('SIGINT'), constant('SIGTERM')] : [];

        self::assertSame($expected, $command->getSubscribedSignals());
    }

    public function testARoundBelowOneFailsBeforeAnyReview(): void
    {
        $tester = $this->whenTheCommandRuns($this->reviewing(Decision::Approve), ['--round' => '0']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('--round must be 1 or more', $tester->getDisplay());
    }

    public function testAMissingTaskFailsBeforeAnyReview(): void
    {
        $tester = $this->whenTheCommandRuns($this->reviewing(Decision::Approve), ['--task' => '']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    public function testTheReviewersReadASnapshotOfTheHeadWhileTheCheckoutStaysWhereItIs(): void
    {
        $this->repository->checkout('main');

        $tester = $this->whenTheCommandRuns($this->reviewingTheSnapshot(static function (string $snapshot): void {
            self::assertStringEqualsFile($snapshot.'/src/Feature.php', "<?php\n");
            self::assertFileExists($snapshot.'/README.md');
        }));

        $tester->assertCommandIsSuccessful($tester->getDisplay());
        self::assertFileDoesNotExist($this->repository->path.'/src/Feature.php');
        self::assertSame($this->repository->objectAt('main'), $this->repository->objectAt('HEAD'));
    }

    // The reviewer's probe: a checkout switched away and back while the reviewers read must not change what they read.
    public function testABranchSwitchedAndRestoredDuringTheRoundDoesNotChangeWhatTheReviewersRead(): void
    {
        $tester = $this->whenTheCommandRuns($this->reviewingTheSnapshot(function (string $snapshot): void {
            $this->repository->checkout('main');
            self::assertFileDoesNotExist($this->repository->path.'/src/Feature.php');
            self::assertStringEqualsFile($snapshot.'/src/Feature.php', "<?php\n");
            $this->repository->checkout('sample');
        }));

        $tester->assertCommandIsSuccessful($tester->getDisplay());
    }

    public function testAnEditInTheCheckoutDoesNotReachTheSnapshot(): void
    {
        file_put_contents($this->repository->path.'/src/Feature.php', "<?php\n// edited\n");
        file_put_contents($this->repository->path.'/notes.txt', "scratch\n");

        $tester = $this->whenTheCommandRuns($this->reviewingTheSnapshot(static function (string $snapshot): void {
            self::assertStringEqualsFile($snapshot.'/src/Feature.php', "<?php\n");
            self::assertFileDoesNotExist($snapshot.'/notes.txt');
        }));

        $tester->assertCommandIsSuccessful($tester->getDisplay());
    }

    public function testTheReviewIsAskedForTheBranchesChangeUnderTheProjectInASnapshot(): void
    {
        $reviews = $this->createMock(ChangeReviews::class);
        $reviews->expects(self::once())->method('review')->with(self::callback(
            fn (ChangeReviewRequest $request): bool => 'alpha' === $request->project && 'sample' === $request->round->change && 2 === $request->round->number && 'Add the thing.' === $request->task && is_dir($request->checkoutRoot) && realpath($this->repository->path) !== $request->checkoutRoot,
        ))->willReturn($this->outcome(Decision::Approve));

        $this->whenTheCommandRuns($reviews, ['--round' => '2']);
    }

    public function testTheReviewIsGivenTheBranchesDiff(): void
    {
        $reviews = $this->createMock(ChangeReviews::class);
        $reviews->expects(self::once())->method('review')->with(self::callback(
            static fn (ChangeReviewRequest $request): bool => str_contains($request->diff, 'diff --git a/') && str_contains($request->diff, "\n+") && str_contains($request->diff, "\n-"),
        ))->willReturn($this->outcome(Decision::Approve));

        $this->whenTheCommandRuns($reviews);
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('README.md', "base\n");
        $this->repository->checkoutNewBranch('sample');
        $this->repository->commitFile('src/Feature.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    /**
     * @param Closure(string): void $look
     */
    private function reviewingTheSnapshot(Closure $look): ChangeReviews
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturnCallback(function (ChangeReviewRequest $request) use ($look): ChangeReview {
            $look($request->checkoutRoot);

            return $this->outcome(Decision::Approve);
        });

        return $reviews;
    }

    private function reviewing(Decision $decision): ChangeReviews
    {
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturn($this->outcome($decision));

        return $reviews;
    }

    /**
     * @param array<string, string> $input
     */
    private function whenTheCommandRuns(ChangeReviews $reviews, array $input = [], ?Panels $panels = null): CommandTester
    {
        return $this->whenTheCommandIsRun(new ReviewChangeCommand(new LocalChangeReview($panels ?? $this->onePanel(), $reviews, new DiskWithRoom()), $this->report()), $input);
    }

    /**
     * @param array<string, string> $input
     */
    private function whenTheCommandIsRun(ReviewChangeCommand $command, array $input = []): CommandTester
    {
        $application = new Application();
        $application->addCommand($command);
        $tester = new CommandTester($application->find('intakt:review'));
        $tester->execute(['change' => 'sample', '--project' => 'alpha', '--task' => 'Add the thing.', '--repository' => $this->repository->path, ...$input]);

        return $tester;
    }

    private function report(): ReviewReport
    {
        return new ReviewReport(StoredSerializerFactory::create());
    }

    private function outcome(Decision $decision): ChangeReview
    {
        $finding = new Finding('F1', Severity::P1, 'src/Feature.php', 3, null, 'Missing check.', FixableBy::Builder, false, null, null);
        $verdicts = [
            new PanelVerdict(Tier::Strong, new Verdict(Decision::Changes, [$finding], [], [])),
            new PanelVerdict(Tier::Standard, new Verdict(Decision::Approve, [], [], [])),
        ];

        return new ChangeReview('0192a000-0000-7000-8000-000000000000', 1, $decision, $verdicts, [$finding], RoundTokens::of(2, new TokenFigures(100, 0, 0, 0, 20)), Cost::inUsd(0.00014), Decision::Blocked === $decision ? new BlockReasons('correctness: provider refused the key') : new BlockReasons());
    }

    private function onePanel(): Panels
    {
        $reviewer = new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn(new Panel([$reviewer], QuorumPolicy::All));

        return $panels;
    }
}
