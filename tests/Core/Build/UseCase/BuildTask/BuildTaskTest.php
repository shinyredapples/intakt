<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use DateTimeImmutable;
use Intakt\Core\Build\UseCase\BuildTask\BuildTask;
use Intakt\Core\Build\UseCase\BuildTask\TaskNotListed;
use Intakt\Core\Build\UseCase\BuildTask\TaskOutcome;
use Intakt\Core\Build\UseCase\BuildTask\TaskStep;
use Intakt\Core\Build\UseCase\BuildTask\TaskStepNotRecorded;
use Intakt\Core\Git\Contract\BuildCloneNotMade;
use Intakt\Core\Git\Contract\BuildClones;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\MirrorNotUpdated;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\ProjectMirror;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\Infrastructure\MirrorBuildClones;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Watcher\Contract\ProjectHasNoRepository;
use Intakt\Core\Watcher\Contract\ProjectRepositories;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;

#[CoversClass(BuildTask::class)]
#[CoversClass(TaskOutcome::class)]
#[CoversClass(TaskStep::class)]
#[CoversClass(TaskNotListed::class)]
#[CoversClass(TaskStepNotRecorded::class)]
#[CoversClass(MirrorBuildClones::class)]
#[UsesClass(ProjectRepositories::class)]
#[UsesClass(ProjectHasNoRepository::class)]
#[UsesClass(MirrorNotUpdated::class)]
#[UsesClass(BuildCloneNotMade::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(MakeBuildClone::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(TaskBranch::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(IssueListing::class)]
#[UsesClass(ListedIssue::class)]
final class BuildTaskTest extends TestCase
{
    private string $folder;

    private TemporaryRepository $remote;

    private InMemoryLedger $ledger;

    /** @var array<string, array{repository: string}>|null */
    private ?array $watched = null;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-build-task-'.bin2hex(random_bytes(6));
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
        $this->ledger = new InMemoryLedger();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
        $this->remote->remove();
    }

    public function testATaskThatPassesEveryStepRecordsThemInOrderAndLeavesACloneOnTheTaskBranch(): void
    {
        $outcome = $this->realGit()->run('alpha', 7);

        self::assertTrue($outcome->passed());
        self::assertSame([TaskStep::Task, TaskStep::Mirror, TaskStep::Clone], $outcome->completed);
        self::assertSame([LedgerEventKind::TaskRead, LedgerEventKind::MirrorUpdated, LedgerEventKind::CloneMade], $this->ledger->kinds());
        self::assertSame($this->folder.'/builds/alpha-7', $outcome->directory);
        self::assertSame('intakt/issue-7', $this->headOfTheClone());
    }

    public function testTheCloneStepIsGivenTheBranchOfTheIssue(): void
    {
        $clones = self::createMock(BuildClones::class);
        $clones->expects(self::once())->method('make')->with('alpha', 'intakt/issue-7', $this->folder.'/builds/alpha-7');

        $this->use($this->issues(7), self::createStub(ProjectMirror::class), $clones)->run('alpha', 7);
    }

    public function testAnIssueTheForgeDoesNotListEndsAtTheTaskStepWithoutTouchingMirrorOrClone(): void
    {
        $mirror = self::createMock(ProjectMirror::class);
        $mirror->expects(self::never())->method('update');
        $clones = self::createMock(BuildClones::class);
        $clones->expects(self::never())->method('make');

        $outcome = $this->use($this->issues(8), $mirror, $clones)->run('alpha', 7);

        self::assertSame(TaskStep::Task, $outcome->failedAt);
        self::assertSame([], $outcome->completed);
        self::assertSame('Project "alpha" has no open issue #7.', $outcome->reason);
        self::assertSame([LedgerEventKind::TaskNotRead], $this->ledger->kinds());
    }

    public function testAPullRequestWithTheNumberIsNoTask(): void
    {
        $issues = self::createStub(IssueReads::class);
        $issues->method('listOpen')->willReturn(IssueListing::listed([$this->listed(7, true)], null));

        $outcome = $this->use($issues, self::createStub(ProjectMirror::class), self::createStub(BuildClones::class))->run('alpha', 7);

        self::assertSame(TaskStep::Task, $outcome->failedAt);
    }

    public function testAMirrorThatFailsEndsAtTheMirrorStepAndNoCloneIsMade(): void
    {
        $mirror = self::createStub(ProjectMirror::class);
        $mirror->method('update')->willThrowException(MirrorNotUpdated::at('mirror', 'the remote is gone'));
        $clones = self::createMock(BuildClones::class);
        $clones->expects(self::never())->method('make');

        $outcome = $this->use($this->issues(7), $mirror, $clones)->run('alpha', 7);

        self::assertSame(TaskStep::Mirror, $outcome->failedAt);
        self::assertSame([TaskStep::Task], $outcome->completed);
        self::assertStringContainsString('the remote is gone', (string) $outcome->reason);
        self::assertSame([LedgerEventKind::TaskRead, LedgerEventKind::MirrorNotUpdated], $this->ledger->kinds());
    }

    public function testAMirrorOfAMissingRemoteFailsOnRealGitAtTheMirrorStep(): void
    {
        $this->remote->remove();

        $outcome = $this->realGit()->run('alpha', 7);

        self::assertSame(TaskStep::Mirror, $outcome->failedAt);
        self::assertSame([LedgerEventKind::TaskRead, LedgerEventKind::MirrorNotUpdated], $this->ledger->kinds());
    }

    public function testAProjectWithoutARepositoryEndsAtTheMirrorStep(): void
    {
        $this->watched = [];
        $outcome = $this->use($this->issues(7), self::createStub(ProjectMirror::class), self::createStub(BuildClones::class))->run('alpha', 7);

        self::assertSame(TaskStep::Mirror, $outcome->failedAt);
        self::assertStringContainsString('has no watch section', (string) $outcome->reason);
    }

    public function testACloneThatFailsEndsAtTheCloneStepAfterTheMirrorWasUpdated(): void
    {
        $clones = self::createStub(BuildClones::class);
        $clones->method('make')->willThrowException(BuildCloneNotMade::at('dir', 'the folder already exists'));

        $outcome = $this->use($this->issues(7), self::createStub(ProjectMirror::class), $clones)->run('alpha', 7);

        self::assertSame(TaskStep::Clone, $outcome->failedAt);
        self::assertSame([TaskStep::Task, TaskStep::Mirror], $outcome->completed);
        self::assertSame([LedgerEventKind::TaskRead, LedgerEventKind::MirrorUpdated, LedgerEventKind::CloneNotMade], $this->ledger->kinds());
    }

    public function testACloneFolderThatAlreadyExistsFailsTheCloneStepOnRealGit(): void
    {
        $task = $this->realGit();
        $task->run('alpha', 7);

        $second = $task->run('alpha', 7);

        self::assertSame(TaskStep::Clone, $second->failedAt);
        self::assertStringContainsString('the folder already exists', (string) $second->reason);
    }

    public function testALedgerThatRefusesAStepStopsTheRunWithAnError(): void
    {
        $this->ledger = new InMemoryLedger(0);

        $this->expectException(TaskStepNotRecorded::class);

        $this->use($this->issues(7), self::createStub(ProjectMirror::class), self::createStub(BuildClones::class))->run('alpha', 7);
    }

    private function realGit(): BuildTask
    {
        $mirrors = new Mirrors($this->folder.'/mirrors');
        $mirror = new UpdateMirror(new HardenedGit(), new NetworkGit(new RecordingClock(), new NullLogger()), new Filesystem(), new LockFactory(new InMemoryStore()), $mirrors);

        return $this->use($this->issues(7), $mirror, new MirrorBuildClones(new MakeBuildClone(new HardenedGit(), new Filesystem(), $mirrors)));
    }

    private function use(IssueReads $issues, ProjectMirror $mirror, BuildClones $clones): BuildTask
    {
        $repositories = new ProjectRepositories($this->watched ?? ['alpha' => ['repository' => $this->remote->path]]);

        return new BuildTask($issues, $repositories, $mirror, $clones, $this->ledger, $this->folder.'/builds');
    }

    private function issues(int $number): IssueReads
    {
        $issues = self::createStub(IssueReads::class);
        $issues->method('listOpen')->willReturn(IssueListing::listed([$this->listed($number, false)], null));

        return $issues;
    }

    private function listed(int $number, bool $isPullRequest): ListedIssue
    {
        return new ListedIssue($number, 'Title', 'Body', [], 'someone', new DateTimeImmutable('2026-01-01T00:00:00Z'), null, $isPullRequest);
    }

    private function headOfTheClone(): string
    {
        $head = new Process(['git', '--git-dir='.$this->folder.'/builds/alpha-7/.git', 'symbolic-ref', '--short', 'HEAD']);
        $head->mustRun();

        return trim($head->getOutput());
    }
}
