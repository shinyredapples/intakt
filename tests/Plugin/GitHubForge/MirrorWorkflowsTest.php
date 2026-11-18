<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Plugin\GitHubForge\Domain\CiWorkflows;
use Intakt\Plugin\GitHubForge\Domain\CommitNotRead;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Intakt\Plugin\GitHubForge\Infrastructure\MirrorWorkflows;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MirrorWorkflows::class)]
#[UsesClass(CiWorkflows::class)]
#[UsesClass(CommitNotRead::class)]
#[UsesClass(WorkflowFile::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class MirrorWorkflowsTest extends TestCase
{
    private TemporaryRepository $repository;

    public function testTheHeadsMainsAndTheMergedWorkflowsAreReadWithEveryPathWhereTheHeadOrTheMergeDiffersFromMain(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', "head's deploy\n");
        $this->repository->commitFile('scripts/test.sh', "test\n");
        $this->repository->checkout('main');
        $this->repository->commitFile('.github/workflows/nightly.yaml', "main's nightly\n");

        $workflows = $this->read();

        self::assertSame(['.github/workflows/ci.yml' => "main's ci\n", '.github/workflows/deploy.yml' => "head's deploy\n"], $this->texts($workflows->head));
        self::assertSame(['.github/workflows/ci.yml' => "main's ci\n", '.github/workflows/nightly.yaml' => "main's nightly\n"], $this->texts($workflows->main));
        self::assertSame(['.github/workflows/ci.yml' => "main's ci\n", '.github/workflows/deploy.yml' => "head's deploy\n", '.github/workflows/nightly.yaml' => "main's nightly\n"], $this->texts($workflows->merged));
        self::assertSame(['.github/workflows/deploy.yml', '.github/workflows/nightly.yaml', 'scripts/test.sh'], $workflows->changedPaths);
    }

    public function testOnlyYamlFilesDirectlyInTheWorkflowsFolderAreRead(): void
    {
        $this->repository->commitFile('.github/workflows/README.md', "notes\n");
        $this->repository->commitFile('.github/workflows/shared/part.yml', "part\n");
        $this->repository->commitFile('.github/workflows/Release.YML', "release\n");

        self::assertSame(['.github/workflows/Release.YML' => "release\n", '.github/workflows/ci.yml' => "main's ci\n"], $this->texts($this->read()->head));
    }

    public function testALinkedWorkflowIsUnreadRatherThanSkipped(): void
    {
        symlink('ci.yml', $this->repository->path.'/.github/workflows/linked.yml');
        $this->repository->commitFile('scripts/test.sh', "test\n");

        self::assertSame(['.github/workflows/ci.yml' => "main's ci\n", '.github/workflows/linked.yml' => 'a blob entry with mode 120000, not a regular file'], $this->texts($this->read()->head));
    }

    public function testALinkedWorkflowsFolderIsUnreadOnTheHeadAndTheMerge(): void
    {
        $this->repository->commitFile('other/ci.yml', "runs-on: self-hosted\n");
        $this->repository->removeFile('.github/workflows/ci.yml');
        rmdir($this->repository->path.'/.github/workflows');
        symlink('../other', $this->repository->path.'/.github/workflows');
        $this->repository->commitFile('scripts/test.sh', "test\n");

        $workflows = $this->read();

        self::assertSame(['.github/workflows' => 'a blob entry with mode 120000, not a folder'], $this->texts($workflows->head));
        self::assertSame(['.github/workflows' => 'a blob entry with mode 120000, not a folder'], $this->texts($workflows->merged));
        self::assertSame(['.github/workflows/ci.yml' => "main's ci\n"], $this->texts($workflows->main));
    }

    public function testALinkedGithubFolderIsUnread(): void
    {
        $this->repository->commitFile('other/workflows/ci.yml', "runs-on: self-hosted\n");
        $this->repository->removeFile('.github/workflows/ci.yml');
        rmdir($this->repository->path.'/.github/workflows');
        rmdir($this->repository->path.'/.github');
        symlink('other', $this->repository->path.'/.github');
        $this->repository->commitFile('scripts/test.sh', "test\n");

        self::assertSame(['.github' => 'a blob entry with mode 120000, not a folder'], $this->texts($this->read()->head));
    }

    public function testAWorkflowsFolderThatIsAFileIsUnread(): void
    {
        $this->repository->removeFile('.github/workflows/ci.yml');
        rmdir($this->repository->path.'/.github/workflows');
        $this->repository->commitFile('.github/workflows', "not a folder\n");

        self::assertSame(['.github/workflows' => 'a blob entry with mode 100644, not a folder'], $this->texts($this->read()->head));
    }

    public function testAConflictWithMainLeavesTheMergedWorkflowsUnread(): void
    {
        $this->repository->commitFile('.github/workflows/ci.yml', "head's ci\n");
        $this->repository->checkout('main');
        $this->repository->commitFile('.github/workflows/ci.yml', "main's new ci\n");

        $workflows = $this->read();

        self::assertSame(['.github/workflows' => 'the head does not merge with main without a conflict'], $this->texts($workflows->merged));
        self::assertSame(['.github/workflows/ci.yml'], $workflows->changedPaths);
    }

    public function testAHeadsChangeThatMainMergedAndRevertedStillCounts(): void
    {
        $this->repository->commitFile('scripts/check.sh', "unapproved\n");
        $this->repository->checkout('main');
        $this->repository->merge('head');
        $this->repository->commitFile('scripts/check.sh', "safe\n");

        self::assertSame(['scripts/check.sh'], $this->read()->changedPaths);
    }

    public function testARelativeGitDirectoryIsReadFromTheCurrentDirectory(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', "head's deploy\n");
        $this->repository->checkout('main');
        $directory = (string) getcwd();
        chdir($this->repository->path);

        try {
            $workflows = new MirrorWorkflows(new HardenedGit())->of('.git', 'head');
        } finally {
            chdir($directory);
        }

        self::assertSame(['.github/workflows/deploy.yml'], $workflows->changedPaths);
    }

    public function testAWorkflowWhoseObjectIsMissingIsUnread(): void
    {
        $this->repository->removeObject($this->repository->objectAt('head:.github/workflows/ci.yml'));

        self::assertStringStartsWith('git cat-file failed: ', $this->texts($this->read()->head)['.github/workflows/ci.yml']);
    }

    public function testAHeadWithoutWorkflowsHasNone(): void
    {
        $this->repository->removeFile('.github/workflows/ci.yml');

        self::assertSame([], $this->read()->head);
    }

    public function testANameThatIsNoCommitIsNotRead(): void
    {
        $this->repository->checkout('main');

        self::assertSame('--output=x names no commit of the repository', $this->failureReading('--output=x'));
    }

    public function testARepositoryWhoseHeadNamesNoBranchHasNoMain(): void
    {
        $this->repository->checkoutDetached('main');

        self::assertSame('the repository\'s HEAD names no branch, so main is unknown', $this->failureReading('head'));
    }

    public function testMainIsTheBranchTheRepositorysHeadNames(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', "head's deploy\n");

        self::assertSame([], $this->readAgainst('head')->changedPaths);
    }

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('.github/workflows/ci.yml', "main's ci\n");
        $this->repository->checkoutNewBranch('head');
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
    }

    private function read(): CiWorkflows
    {
        return $this->readAgainst('main');
    }

    private function readAgainst(string $main): CiWorkflows
    {
        $this->repository->checkout($main);

        return new MirrorWorkflows(new HardenedGit())->of($this->repository->path.'/.git', 'head');
    }

    private function failureReading(string $head): string
    {
        try {
            new MirrorWorkflows(new HardenedGit())->of($this->repository->path.'/.git', $head);
        } catch (CommitNotRead $notRead) {
            return $notRead->getMessage();
        }

        self::fail('The read did not fail.');
    }

    /**
     * @param list<WorkflowFile> $workflows
     *
     * @return array<string, string>
     */
    private function texts(array $workflows): array
    {
        $texts = [];
        foreach ($workflows as $workflow) {
            $texts[$workflow->path] = $workflow->content ?? $workflow->notReadBecause;
        }

        return $texts;
    }
}
