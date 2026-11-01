<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
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
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Closure;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\OutputFormat;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeCommand;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewChangeInput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewExit;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewReport;
use Intakt\Tests\Core\Git\TemporaryRepository;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(ReviewChangeCommand::class)]
#[CoversClass(ReviewReport::class)]
#[CoversClass(ReviewExit::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(ReviewChangeInput::class)]
#[UsesClass(OutputFormat::class)]
final class ReviewChangeCommandTest extends TestCase
{
    private const string SCHEMA = __DIR__.'/../../../docs/user/review-output.schema.json';

    private TemporaryRepository $repository;

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

    public function testTheTextOutputPrintsTheOutcomeTheActListAndTheTokens(): void
    {
        $tester = $this->whenTheCommandRuns($this->reviewing(Decision::Changes));

        self::assertSame(
            "changes (round 1)\nstrong reviewer: changes, 1 findings\nstandard reviewer: approve, 0 findings\nact list: 1 findings\nF1 P1 src/Feature.php:3: Missing check.\ntokens: 2 calls, 100 prompt, 20 completion, 120 total\n",
            $tester->getDisplay(),
        );
    }

    #[DataProvider('decisions')]
    public function testTheJsonOutputValidatesAgainstTheDocumentedSchema(Decision $decision, int $code): void
    {
        $tester = $this->whenTheCommandRuns($this->reviewing($decision), ['--format' => 'json']);

        self::assertSame([], $this->schemaErrors($tester->getDisplay()), 'exit '.$code);
    }

    public function testTheJsonOutputCarriesTheDecisionAndItsExitCode(): void
    {
        $tester = $this->whenTheCommandRuns($this->reviewing(Decision::Blocked), ['--format' => 'json']);

        $document = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        self::assertSame(['blocked', 11], [$document['decision'], $document['exit_code']]);
    }

    public function testTheSchemaRefusesAnOutputWithoutItsTokens(): void
    {
        $document = json_decode($this->whenTheCommandRuns($this->reviewing(Decision::Approve), ['--format' => 'json'])->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        unset($document['tokens']);

        self::assertNotSame([], $this->schemaErrors(json_encode($document, JSON_THROW_ON_ERROR)));
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

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
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

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function testAnEditInTheCheckoutDoesNotReachTheSnapshot(): void
    {
        file_put_contents($this->repository->path.'/src/Feature.php', "<?php\n// edited\n");
        file_put_contents($this->repository->path.'/notes.txt', "scratch\n");

        $tester = $this->whenTheCommandRuns($this->reviewingTheSnapshot(static function (string $snapshot): void {
            self::assertStringEqualsFile($snapshot.'/src/Feature.php', "<?php\n");
            self::assertFileDoesNotExist($snapshot.'/notes.txt');
        }));

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    public function testTheSnapshotIsRemovedAfterTheRound(): void
    {
        $snapshot = '';
        $tester = $this->whenTheCommandRuns($this->reviewingTheSnapshot(static function (string $directory) use (&$snapshot): void {
            $snapshot = $directory;
        }));

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertDirectoryDoesNotExist($snapshot);
        self::assertCount(1, $this->repository->worktrees());
    }

    public function testTheSnapshotIsRemovedWhenTheReviewIsRefused(): void
    {
        $snapshot = '';
        $reviews = self::createStub(ChangeReviews::class);
        $reviews->method('review')->willReturnCallback(static function (ChangeReviewRequest $request) use (&$snapshot): never {
            $snapshot = $request->checkoutRoot;

            throw RoundNotNext::for(3, 1);
        });

        $tester = $this->whenTheCommandRuns($reviews, ['--round' => '3']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($snapshot);
        self::assertCount(1, $this->repository->worktrees());
    }

    public function testTheReviewIsAskedForTheBranchesChangeUnderTheProjectInASnapshot(): void
    {
        $reviews = $this->createMock(ChangeReviews::class);
        $reviews->expects(self::once())->method('review')->with(self::callback(
            fn (ChangeReviewRequest $request): bool => 'alpha' === $request->project && 'sample' === $request->round->change && 2 === $request->round->number && 'Add the thing.' === $request->task && is_dir($request->checkoutRoot) && realpath($this->repository->path) !== $request->checkoutRoot,
        ))->willReturn($this->outcome(Decision::Approve));

        $this->whenTheCommandRuns($reviews, ['--round' => '2']);
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

    private function outcome(Decision $decision): ChangeReview
    {
        $finding = new Finding('F1', Severity::P1, 'src/Feature.php', 3, null, 'Missing check.', FixableBy::Builder, false, null, null);
        $verdicts = [
            new PanelVerdict(Tier::Strong, new Verdict(Decision::Changes, [$finding], [], [])),
            new PanelVerdict(Tier::Standard, new Verdict(Decision::Approve, [], [], [])),
        ];

        return new ChangeReview('0192a000-0000-7000-8000-000000000000', 1, $decision, $verdicts, [$finding], new RoundTokens(2, 100, 20, 120), Decision::Blocked === $decision ? ['correctness: provider refused the key'] : []);
    }

    /**
     * @param array<string, string> $input
     */
    private function whenTheCommandRuns(ChangeReviews $reviews, array $input = [], ?Panels $panels = null): CommandTester
    {
        $application = new Application();
        $application->addCommand(new ReviewChangeCommand($panels ?? $this->onePanel(), $reviews, new NameStatusParser()));
        $tester = new CommandTester($application->find('intakt:review'));
        $tester->execute(['change' => 'sample', '--project' => 'alpha', '--task' => 'Add the thing.', '--repository' => $this->repository->path, ...$input]);

        return $tester;
    }

    private function onePanel(): Panels
    {
        $reviewer = new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn(new Panel([$reviewer], QuorumPolicy::All));

        return $panels;
    }

    /**
     * @return list<mixed>
     */
    private function schemaErrors(string $output): array
    {
        $validator = new Validator();
        $document = json_decode($output, flags: JSON_THROW_ON_ERROR);
        $validator->validate($document, json_decode((string) file_get_contents(self::SCHEMA)));

        return array_values($validator->getErrors());
    }
}
