<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Infrastructure\WorktreeGitDirectories;
use Intakt\Plugin\LocalGitForge\Infrastructure\WorktreeLocks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(WorktreeLocks::class)]
#[CoversClass(WorktreeGitDirectories::class)]
#[UsesClass(ForgeReadFailed::class)]
final class WorktreeLocksTest extends TestCase
{
    private string $common;

    public function testItTakesTheIndexLockBeforeTheHeadLockOfTheRepositoryAndOfEveryLinkedWorktree(): void
    {
        new Filesystem()->mkdir($this->common.'/worktrees/.linked');

        $locks = new WorktreeLocks()->take($this->common);

        self::assertSame([
            $this->common.'/index.lock',
            $this->common.'/HEAD.lock',
            $this->common.'/worktrees/.linked/index.lock',
            $this->common.'/worktrees/.linked/HEAD.lock',
        ], $locks);
        self::assertFileExists($this->common.'/worktrees/.linked/HEAD.lock');
    }

    public function testItTakesOnlyTheRepositorysLocksWhenThereIsNoLinkedWorktree(): void
    {
        self::assertSame([$this->common.'/index.lock', $this->common.'/HEAD.lock'], new WorktreeLocks()->take($this->common));
    }

    public function testALockThatExistsIsRefusedAndTheLocksAlreadyTakenAreReleased(): void
    {
        new Filesystem()->touch($this->common.'/HEAD.lock');

        try {
            new WorktreeLocks()->take($this->common);
            self::fail('A lock that exists was taken.');
        } catch (ForgeReadFailed) {
            self::assertFileDoesNotExist($this->common.'/index.lock');
            self::assertFileExists($this->common.'/HEAD.lock');
        }
    }

    public function testReleasingRemovesTheLocksItTook(): void
    {
        $locks = new WorktreeLocks();

        $locks->release($locks->take($this->common));

        self::assertFileDoesNotExist($this->common.'/index.lock');
        self::assertFileDoesNotExist($this->common.'/HEAD.lock');
    }

    protected function setUp(): void
    {
        $this->common = sys_get_temp_dir().'/intakt-worktree-locks-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->common);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->common);
    }
}
