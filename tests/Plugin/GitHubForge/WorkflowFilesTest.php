<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Intakt\Plugin\GitHubForge\Infrastructure\WorkflowFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(WorkflowFiles::class)]
#[UsesClass(WorkflowFile::class)]
final class WorkflowFilesTest extends TestCase
{
    private const string PRIVATE = 'credentials: {token: private-sentinel}';

    private string $root;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->root = sys_get_temp_dir().'/intakt-workflow-files-'.bin2hex(random_bytes(6));
        $this->filesystem->dumpFile($this->root.'/private.yaml', self::PRIVATE);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->root);
    }

    public function testEveryWorkflowEntryIsReadInNameOrder(): void
    {
        $this->filesystem->dumpFile($this->root.'/checkout/.github/workflows/ci.yml', 'on: push');
        $this->filesystem->dumpFile($this->root.'/checkout/.github/workflows/build.yaml', 'on: pull_request');

        self::assertEquals(
            [new WorkflowFile('.github/workflows/build.yaml', 'on: pull_request'), new WorkflowFile('.github/workflows/ci.yml', 'on: push')],
            new WorkflowFiles($this->filesystem)->in($this->root.'/checkout'),
        );
    }

    public function testALinkedWorkflowEntryIsPassedOnUnreadWithoutBeingFollowed(): void
    {
        $this->filesystem->mkdir($this->root.'/checkout/.github/workflows');
        $this->filesystem->symlink($this->root.'/private.yaml', $this->root.'/checkout/.github/workflows/leak.yml');

        self::assertEquals([new WorkflowFile('.github/workflows/leak.yml', null)], new WorkflowFiles($this->filesystem)->in($this->root.'/checkout'));
    }

    public function testALinkedWorkflowsFolderIsPassedOnUnreadWithoutBeingFollowed(): void
    {
        $this->filesystem->dumpFile($this->root.'/elsewhere/ci.yml', self::PRIVATE);
        $this->filesystem->mkdir($this->root.'/checkout/.github');
        $this->filesystem->symlink($this->root.'/elsewhere', $this->root.'/checkout/.github/workflows');

        self::assertEquals([new WorkflowFile('.github/workflows', null)], new WorkflowFiles($this->filesystem)->in($this->root.'/checkout'));
    }

    public function testALinkedGithubFolderIsPassedOnUnreadWithoutBeingFollowed(): void
    {
        $this->filesystem->dumpFile($this->root.'/elsewhere/workflows/ci.yml', self::PRIVATE);
        $this->filesystem->mkdir($this->root.'/checkout');
        $this->filesystem->symlink($this->root.'/elsewhere', $this->root.'/checkout/.github');

        self::assertEquals([new WorkflowFile('.github', null)], new WorkflowFiles($this->filesystem)->in($this->root.'/checkout'));
    }
}
