<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\LocalGitForge\Infrastructure\RebasesAndBisects;
use Intakt\Plugin\LocalGitForge\Infrastructure\WorktreeGitDirectories;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(RebasesAndBisects::class)]
#[UsesClass(ForgeReadFailed::class)]
#[UsesClass(WorktreeGitDirectories::class)]
final class RebasesAndBisectsTest extends TestCase
{
    private string $common;

    public function testItNamesNoBranchWhenNoRebaseNorBisectIsUnderWay(): void
    {
        self::assertSame([], new RebasesAndBisects()->branchesHeldIn($this->common));
    }

    public function testItNamesTheBranchOfEachRebaseAndBisectInTheRepositoryAndInEveryLinkedWorktree(): void
    {
        new Filesystem()->dumpFile($this->common.'/rebase-merge/head-name', "refs/heads/intakt/merged\n");
        new Filesystem()->dumpFile($this->common.'/worktrees/linked/rebase-apply/rebasing', '');
        new Filesystem()->dumpFile($this->common.'/worktrees/linked/rebase-apply/head-name', "refs/heads/intakt/applied\n");
        new Filesystem()->dumpFile($this->common.'/worktrees/linked/BISECT_START', "intakt/bisected\n");

        self::assertSame(
            ['refs/heads/intakt/merged', 'refs/heads/intakt/applied', 'refs/heads/intakt/bisected'],
            new RebasesAndBisects()->branchesHeldIn($this->common),
        );
    }

    public function testAnAmUnderWayNamesNoBranchBecauseItKeepsHeadOnItsBranch(): void
    {
        new Filesystem()->dumpFile($this->common.'/rebase-apply/applying', '');

        self::assertSame([], new RebasesAndBisects()->branchesHeldIn($this->common));
    }

    public function testARebaseStartedOnADetachedHeadNamesNoBranch(): void
    {
        new Filesystem()->dumpFile($this->common.'/rebase-merge/head-name', "detached HEAD\n");
        new Filesystem()->dumpFile($this->common.'/rebase-apply/rebasing', '');
        new Filesystem()->dumpFile($this->common.'/rebase-apply/head-name', "detached HEAD\n");

        self::assertSame([], new RebasesAndBisects()->branchesHeldIn($this->common));
    }

    public function testAMergeRebaseWithoutAHeadNameThrowsBecauseItsBranchCannotBeTold(): void
    {
        new Filesystem()->mkdir($this->common.'/rebase-merge');

        $this->expectException(ForgeReadFailed::class);

        new RebasesAndBisects()->branchesHeldIn($this->common);
    }

    public function testAMergeRebaseWithAnEmptyHeadNameThrowsBecauseItsBranchCannotBeTold(): void
    {
        new Filesystem()->dumpFile($this->common.'/rebase-merge/head-name', "\n");

        $this->expectException(ForgeReadFailed::class);

        new RebasesAndBisects()->branchesHeldIn($this->common);
    }

    public function testAnApplyRebaseWithoutAHeadNameThrowsBecauseItsBranchCannotBeTold(): void
    {
        new Filesystem()->dumpFile($this->common.'/rebase-apply/rebasing', '');

        $this->expectException(ForgeReadFailed::class);

        new RebasesAndBisects()->branchesHeldIn($this->common);
    }

    public function testAnApplyRebaseWithAnEmptyHeadNameThrowsBecauseItsBranchCannotBeTold(): void
    {
        new Filesystem()->dumpFile($this->common.'/rebase-apply/rebasing', '');
        new Filesystem()->dumpFile($this->common.'/rebase-apply/head-name', '');

        $this->expectException(ForgeReadFailed::class);

        new RebasesAndBisects()->branchesHeldIn($this->common);
    }

    public function testAnApplyStateWithNeitherARebaseNorAnAmMarkerThrowsBecauseItsBranchCannotBeTold(): void
    {
        new Filesystem()->dumpFile($this->common.'/rebase-apply/head-name', "refs/heads/intakt/applied\n");

        $this->expectException(ForgeReadFailed::class);

        new RebasesAndBisects()->branchesHeldIn($this->common);
    }

    public function testABisectStateThatCannotBeReadThrows(): void
    {
        new Filesystem()->mkdir($this->common.'/BISECT_START');

        $this->expectException(ForgeReadFailed::class);

        new RebasesAndBisects()->branchesHeldIn($this->common);
    }

    protected function setUp(): void
    {
        $this->common = sys_get_temp_dir().'/intakt-rebases-and-bisects-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->common);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->common);
    }
}
