<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\Build\UseCase\BuildTask\BuildStep;
use Intakt\Core\Build\UseCase\BuildTask\BuildStepNotRecorded;
use Intakt\Core\Build\UseCase\BuildTask\BuildTask;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskOutcome;
use Intakt\Core\Build\UseCase\BuildTask\BuildTaskRequest;
use Intakt\Core\Git\Contract\BuildCheckoutFailed;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\TaskBranch;
use Intakt\Core\Git\Infrastructure\GitBuildCheckout;
use Intakt\Core\Git\UseCase\MakeBuildClone\BuildCloneNotMade;
use Intakt\Core\Git\UseCase\MakeBuildClone\MakeBuildClone;
use Intakt\Core\Git\UseCase\UpdateMirror\MirrorNotUpdated;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Plugin\GitHubWorkSource\Issues;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(BuildTask::class)]
#[CoversClass(BuildTaskOutcome::class)]
#[CoversClass(BuildTaskRequest::class)]
#[CoversClass(BuildStepNotRecorded::class)]
#[CoversClass(GitBuildCheckout::class)]
#[CoversClass(BuildCheckoutFailed::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(MakeBuildClone::class)]
#[UsesClass(MirrorNotUpdated::class)]
#[UsesClass(BuildCloneNotMade::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(TaskBranch::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(IssueListing::class)]
#[UsesClass(Issues::class)]
final class BuildTaskTest extends TestCase
{
    private string $mirrors;

    private string $clone;

    private TemporaryRepository $remote;

    protected function setUp(): void
    {
        $this->mirrors = sys_get_temp_dir().'/intakt-build-task-'.bin2hex(random_bytes(6));
        $this->clone = $this->mirrors.'-clone';
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->mirrors, $this->clone]);
        $this->remote->remove();
    }

    public function testATaskWhoseStepsAllPassRecordsTaskMirrorAndCloneInOrder(): void
    {
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertFalse($outcome->failed());
        self::assertSame([BuildStep::Task, BuildStep::Mirror, BuildStep::Clone], $outcome->passedSteps());
        self::assertSame('Task 7', $outcome->task?->title);
        self::assertSame([LedgerEventKind::BuildStepEnded, LedgerEventKind::BuildStepEnded, LedgerEventKind::BuildStepEnded], $ledger->kinds());
        self::assertSame(['task', 'mirror', 'clone'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'passed', 'passed'], array_column($ledger->payloads(), 'outcome'));
        self::assertSame('7', $ledger->payloads()[0]['task']);
        self::assertFileExists($this->clone.'/a.txt');
    }

    public function testAnIssueTheForgeDoesNotListEndsAtTheTaskStepAndNoMirrorIsMade(): void
    {
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(8)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Task, $outcome->last);
        self::assertSame('project "alpha" has no open issue 7', $outcome->failure);
        self::assertSame([], $outcome->passedSteps());
        self::assertSame(['task'], array_column($ledger->payloads(), 'step'));
        self::assertSame('failed', $ledger->payloads()[0]['outcome']);
        self::assertDirectoryDoesNotExist($this->mirrors);
    }

    public function testAPullRequestOfTheSameNumberIsNoTask(): void
    {
        $outcome = $this->taskOf(new InMemoryLedger(), [Issues::pullRequest(7, '')])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Task, $outcome->last);
        self::assertTrue($outcome->failed());
    }

    public function testAForgeThatCannotBeReadEndsAtTheTaskStepWithItsMessage(): void
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('listOpen')->willThrowException(new ForgeReadFailed('GitHub answered 500'));
        $ledger = new InMemoryLedger();

        $outcome = new BuildTask($reads, $this->checkout(), $ledger)->run($this->request(7, $this->remote->path));

        self::assertSame('GitHub answered 500', $outcome->failure);
        self::assertSame(['task'], array_column($ledger->payloads(), 'step'));
        self::assertDirectoryDoesNotExist($this->mirrors);
    }

    public function testAMirrorThatCannotBeUpdatedEndsAtTheMirrorStepAndNoCloneIsMade(): void
    {
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->mirrors.'-missing-remote'));

        self::assertSame(BuildStep::Mirror, $outcome->last);
        self::assertSame([BuildStep::Task], $outcome->passedSteps());
        self::assertSame(['task', 'mirror'], array_column($ledger->payloads(), 'step'));
        self::assertSame(['passed', 'failed'], array_column($ledger->payloads(), 'outcome'));
        self::assertNotEmpty($ledger->payloads()[1]['failure']);
        self::assertDirectoryDoesNotExist($this->clone);
    }

    public function testABuildCloneThatCannotBeMadeEndsAtTheCloneStepAndLeavesTheFolderAlone(): void
    {
        mkdir($this->clone);
        $ledger = new InMemoryLedger();

        $outcome = $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));

        self::assertSame(BuildStep::Clone, $outcome->last);
        self::assertStringContainsString('the folder already exists', (string) $outcome->failure);
        self::assertSame([BuildStep::Task, BuildStep::Mirror], $outcome->passedSteps());
        self::assertSame(['task', 'mirror', 'clone'], array_column($ledger->payloads(), 'step'));
        self::assertSame('failed', $ledger->payloads()[2]['outcome']);
    }

    public function testABranchGitDoesNotAcceptEndsAtTheCloneStep(): void
    {
        $request = new BuildTaskRequest('alpha', 7, $this->remote->path, 'bad..name', $this->clone);

        $outcome = $this->taskOf(new InMemoryLedger(), [Issues::task(7)])->run($request);

        self::assertSame(BuildStep::Clone, $outcome->last);
        self::assertStringContainsString('is not a branch name git accepts', (string) $outcome->failure);
    }

    public function testALedgerThatDoesNotRecordAStepStopsTheRunBeforeTheNextStep(): void
    {
        $ledger = new InMemoryLedger(1);

        try {
            (void) $this->taskOf($ledger, [Issues::task(7)])->run($this->request(7, $this->remote->path));
            self::fail('the run must stop');
        } catch (BuildStepNotRecorded $failure) {
            self::assertStringContainsString('did not record the mirror step of issue 7', $failure->getMessage());
        }

        self::assertDirectoryDoesNotExist($this->clone);
    }

    /**
     * @param list<ListedIssue> $issues
     */
    private function taskOf(InMemoryLedger $ledger, array $issues): BuildTask
    {
        $reads = self::createStub(IssueReads::class);
        $reads->method('listOpen')->willReturn(IssueListing::listed($issues, null));

        return new BuildTask($reads, $this->checkout(), $ledger);
    }

    private function checkout(): GitBuildCheckout
    {
        $mirrors = new Mirrors($this->mirrors);
        $network = new NetworkGit(new RecordingClock(), new NullLogger());
        $update = new UpdateMirror(new HardenedGit(), $network, new Filesystem(), new LockFactory(new InMemoryStore()), $mirrors);

        return new GitBuildCheckout($update, new MakeBuildClone(new HardenedGit(), new Filesystem(), $mirrors));
    }

    private function request(int $issue, string $remote): BuildTaskRequest
    {
        return new BuildTaskRequest('alpha', $issue, $remote, 'feat/task', $this->clone);
    }
}
