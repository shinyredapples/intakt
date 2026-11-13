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

    public function testTheHeadsMainsAndTheMergedWorkflowsAreReadWithWhatTheMergeChanges(): void
    {
        $this->repository->commitFile('.github/workflows/deploy.yml', "head's deploy\n");
        $this->repository->commitFile('scripts/test.sh', "test\n");
        $this->repository->checkout('main');
        $this->repository->commitFile('.github/workflows/nightly.yaml', "main's nightly\n");

        $workflows = $this->read();

        self::assertSame(['.github/workflows/ci.yml' => "main's ci\n", '.github/workflows/deploy.yml' => "head's deploy\n"], $this->texts($workflows->head));
        self::assertSame(['.github/workflows/ci.yml' => "main's ci\n", '.github/workflows/nightly.yaml' => "main's nightly\n"], $this->texts($workflows->main));
        self::assertSame(['.github/workflows/ci.yml' => "main's ci\n", '.github/workflows/deploy.yml' => "head's deploy\n", '.github/workflows/nightly.yaml' => "main's nightly\n"], $this->texts($workflows->merged));
        self::assertSame(['.github/workflows/deploy.yml', 'scripts/test.sh'], $workflows->changedPaths);
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

    public function testAConflictWithMainLeavesTheMergedWorkflowsUnread(): void
    {
        $this->repository->commitFile('.github/workflows/ci.yml', "head's ci\n");
        $this->repository->checkout('main');
        $this->repository->commitFile('.github/workflows/ci.yml', "main's new ci\n");

        $workflows = $this->read();

        self::assertSame(['.github/workflows' => 'the head does not merge with main without a conflict'], $this->texts($workflows->merged));
        self::assertSame(['.github/workflows/ci.yml'], $workflows->changedPaths);
    }

    public function testAHeadWithoutWorkflowsHasNone(): void
    {
        $this->repository->removeFile('.github/workflows/ci.yml');

        self::assertSame([], $this->read()->head);
    }

    public function testANameThatIsNoCommitIsNotRead(): void
    {
        $this->expectException(CommitNotRead::class);
        $this->expectExceptionMessage('--output=x names no commit of the repository');

        $_ = new MirrorWorkflows(new HardenedGit())->of($this->repository->path.'/.git', 'main', '--output=x');
    }

    private function read(): CiWorkflows
    {
        return new MirrorWorkflows(new HardenedGit())->of($this->repository->path.'/.git', 'main', 'head');
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
