<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Review\Contract\ChangeDescription;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Panel;
use Intakt\Core\Review\Contract\PanelNotConfigured;
use Intakt\Core\Review\Contract\PanelReviewer;
use Intakt\Core\Review\Contract\Panels;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\RecordedIdentityMalformed;
use Intakt\Core\Review\Contract\ReviewerModel;
use Intakt\Core\Review\Contract\ReviewerRole;
use Intakt\Core\Review\Contract\ReviewTermMalformed;
use Intakt\Core\Review\Contract\ReviewTerms;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Core\Review\Contract\VerdictStanding;
use Intakt\Plugin\Forge\Contract\Change;
use Intakt\Plugin\Forge\Contract\ChangedFile;
use Intakt\Plugin\Forge\Contract\ChangedFiles;
use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\CommitIdMalformed;
use Intakt\Plugin\Forge\Contract\CommitRange;
use Intakt\Plugin\Forge\Contract\Diff;
use Intakt\Plugin\Forge\Contract\FileStatus;
use Intakt\Plugin\Forge\Contract\HeadMove;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Contract\RepositoryNotFound;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;
use Intakt\Plugin\LocalGitForge\Infrastructure\VisiblePath;
use Intakt\Plugin\LocalGitForge\UseCase\CheckVerdictIdentity\CheckVerdictIdentityCommand;
use Intakt\Plugin\LocalGitForge\UseCase\CheckVerdictIdentity\CheckVerdictIdentityInput;
use Intakt\Plugin\LocalGitForge\UseCase\CheckVerdictIdentity\RecordedIdentityUnreadable;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Plugin\Forge\CommitIdCheckThatThrows;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(CheckVerdictIdentityCommand::class)]
#[UsesClass(CheckVerdictIdentityInput::class)]
#[UsesClass(LocalGitForge::class)]
#[UsesClass(NameStatusParser::class)]
#[UsesClass(VisiblePath::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(RepositoryNotFound::class)]
#[UsesClass(RecordedIdentityUnreadable::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(Change::class)]
#[UsesClass(ChangeId::class)]
#[UsesClass(ChangedFile::class)]
#[UsesClass(ChangedFiles::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(CommitRange::class)]
#[UsesClass(Diff::class)]
#[UsesClass(FileStatus::class)]
#[UsesClass(HeadMove::class)]
#[UsesClass(ChangeDescription::class)]
#[UsesClass(Decision::class)]
#[UsesClass(Panel::class)]
#[UsesClass(PanelNotConfigured::class)]
#[UsesClass(PanelReviewer::class)]
#[UsesClass(PanelVerdict::class)]
#[UsesClass(QuorumPolicy::class)]
#[UsesClass(ReviewerModel::class)]
#[UsesClass(ReviewerRole::class)]
#[UsesClass(ReviewTerms::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(VerdictIdentity::class)]
#[UsesClass(RecordedIdentityMalformed::class)]
#[UsesClass(ReviewTermMalformed::class)]
#[UsesClass(CommitIdMalformed::class)]
#[UsesClass(VerdictStanding::class)]
final class CheckVerdictIdentityCommandTest extends TestCase
{
    private TemporaryRepository $repository;

    public function testItPrintsTheIdentityOfTheBranchStartingWithItsHeadAndBase(): void
    {
        $tester = $this->whenTheCommandRuns([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringStartsWith(sprintf('identity %s.%s.', $this->repository->objectAt('sample'), $this->repository->objectAt('main')), $tester->getDisplay());
    }

    public function testAVerdictOnTheUnchangedBranchIsCurrent(): void
    {
        $reviewed = $this->recordedIdentity();

        $tester = $this->whenTheCommandRuns(['--reviewed' => $reviewed]);

        self::assertStringEndsWith("\ncurrent\n", $tester->getDisplay());
    }

    public function testAVerdictIsStaleAfterANewCommitOnTheBranch(): void
    {
        $reviewed = $this->recordedIdentity();
        $this->repository->commitFile('src/Feature.php', "<?php\necho 1;\n");

        $tester = $this->whenTheCommandRuns(['--reviewed' => $reviewed]);

        self::assertStringEndsWith("\nstale: head changed\n", $tester->getDisplay());
    }

    public function testAVerdictIsStaleForAnotherTask(): void
    {
        $reviewed = $this->recordedIdentity();

        $tester = $this->whenTheCommandRuns(['task' => 'Add the thing, and the other thing.', '--reviewed' => $reviewed]);

        self::assertStringEndsWith("\nstale: task-revision changed\n", $tester->getDisplay());
    }

    public function testAVerdictIsKeptForTheUntouchedPartsAfterADocumentationOnlyCommit(): void
    {
        $reviewed = $this->recordedIdentity();
        $this->repository->commitFile('docs/feature.md', "feature\n");

        $tester = $this->whenTheCommandRuns(['--reviewed' => $reviewed]);

        self::assertStringEndsWith("\nkept-for-untouched-parts: head changed\nreview again docs/feature.md\n", $tester->getDisplay());
    }

    public function testAVerdictIsKeptForTheUntouchedPartsAfterACleanMergeOfTheBase(): void
    {
        $reviewed = $this->recordedIdentity();
        $this->repository->checkout('main');
        $this->repository->commitFile('src/Main.php', "<?php\n");
        $this->repository->checkout('sample');
        $this->repository->merge('main');

        $tester = $this->whenTheCommandRuns(['--reviewed' => $reviewed]);

        self::assertStringEndsWith("\nkept-for-untouched-parts: head, base changed\nreview again src/Main.php\n", $tester->getDisplay());
    }

    public function testARecordedIdentityWithoutFivePartsIsReportedWithTheSameMessage(): void
    {
        $tester = $this->whenTheCommandRuns(['--reviewed' => 'not-an-identity']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('[ERROR] --reviewed is not a recorded verdict identity: A recorded verdict identity has five parts separated by dots.', $this->errorOf($tester));
    }

    public function testARecordedIdentityWithAnAbbreviatedHeadIsReportedWithTheSameMessage(): void
    {
        $tester = $this->whenTheCommandRuns(['--reviewed' => 'abc1234.'.substr($this->recordedIdentity(), 41)]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('[ERROR] --reviewed is not a recorded verdict identity: A commit id is a full lowercase hexadecimal hash.', $this->errorOf($tester));
    }

    public function testARecordedIdentityWhoseTermsAreNoHashesIsReportedWithTheSameMessage(): void
    {
        [$head, $base] = explode('.', $this->recordedIdentity());

        $tester = $this->whenTheCommandRuns(['--reviewed' => $head.'.'.$base.'.x.y.z']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame('[ERROR] --reviewed is not a recorded verdict identity: A review term is a lowercase hexadecimal SHA-256 hash.', $this->errorOf($tester));
    }

    public function testAProjectWithoutAPanelFailsTheCommand(): void
    {
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willThrowException(new PanelNotConfigured('beta'));

        $tester = $this->whenTheCommandRuns(['project' => 'beta'], $panels);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Project "beta" has no review panel configured.', $tester->getDisplay());
    }

    public function testAnInvalidArgumentFromInsideTheCheckIsNotCaught(): void
    {
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willThrowException(new InvalidArgumentException('a programming error'));

        $this->expectExceptionObject(new InvalidArgumentException('a programming error'));

        $this->whenTheCommandRuns([], $panels);
    }

    #[RunInSeparateProcess]
    public function testAnUnexpectedInvalidArgumentWhileReadingTheReviewedIdentityPassesThrough(): void
    {
        $unexpected = str_repeat('f', 40);
        CommitIdCheckThatThrows::defineFor($unexpected);
        $reviewed = $unexpected.substr($this->recordedIdentity(), 40);

        $this->expectExceptionObject(new InvalidArgumentException('unexpected'));

        $this->whenTheCommandRuns(['--reviewed' => $reviewed]);
    }

    public function testAMissingRepositoryFailsNamingThePath(): void
    {
        $tester = $this->whenTheCommandRuns(['--repository' => 'no-such-repository']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString(RepositoryNotFound::at('no-such-repository')->getMessage(), $tester->getDisplay());
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

    private function recordedIdentity(): string
    {
        $display = $this->whenTheCommandRuns([])->getDisplay();

        return substr(trim($display), strlen('identity '));
    }

    /**
     * @param array<string, string> $input
     */
    private function whenTheCommandRuns(array $input, ?Panels $panels = null): CommandTester
    {
        $application = new Application();
        $application->addCommand(new CheckVerdictIdentityCommand($panels ?? $this->onePanel(), new NameStatusParser()));
        $tester = new CommandTester($application->find('intakt:verdict:identity'));
        $tester->execute([
            'project' => 'alpha',
            'branch' => 'sample',
            'task' => 'Add the thing.',
            'check-command' => 'bin/check',
            '--repository' => $this->repository->path,
            ...$input,
        ]);

        return $tester;
    }

    // The error block wraps at the terminal width, so its words are compared on one line.
    private function errorOf(CommandTester $tester): string
    {
        $display = $tester->getDisplay();
        $error = substr($display, (int) strpos($display, '[ERROR]'));

        return trim((string) preg_replace('/\s+/', ' ', $error));
    }

    private function onePanel(): Panels
    {
        $reviewer = new PanelReviewer(new ReviewerRole('core', 'Review the change.'), new ReviewerModel(Tier::Strong, Provider::Anthropic), 10);
        $panels = self::createStub(Panels::class);
        $panels->method('panelForChange')->willReturn(new Panel([$reviewer], QuorumPolicy::All));

        return $panels;
    }
}
