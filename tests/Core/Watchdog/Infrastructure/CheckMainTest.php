<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use Intakt\Core\Watchdog\Infrastructure\FirstParentChain;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;

#[CoversClass(CommitClassifier::class)]
#[CoversClass(MainHistory::class)]
#[UsesClass(FirstParentChain::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(MainNotRead::class)]
#[UsesClass(Classification::class)]
#[UsesClass(CommitName::class)]
#[UsesClass(MainViolation::class)]
#[UsesClass(RecordedMerge::class)]
#[UsesClass(WatchedMain::class)]
final class CheckMainTest extends TestCase
{
    private TemporaryRepository $repository;

    private ?string $bin = null;

    private ?string $path = null;

    public function testATaskBranchThatMergedMainAndBecameMainIsCleanWhenRecordedWithItsBase(): void
    {
        $base = $this->taskBranchThatMergedMain();
        $head = $this->repository->objectAt('task');
        $this->fastForwardMainTo('task');

        $classification = $this->classify(new WatchedMain($base, [new RecordedMerge($head, $base)]));

        self::assertTrue($classification->isClean());
    }

    public function testAnUnsignedTaskCommitTheRecordedHeadDoesNotReachIsUnsigned(): void
    {
        $base = $this->taskBranchThatMergedMain();
        $head = $this->repository->objectAt('task');
        $extra = $this->repository->commitEmpty('Extra task commit after the recorded head');
        $this->fastForwardMainTo('task');

        $classification = $this->classify(new WatchedMain($base, [new RecordedMerge($head, $base)]));

        self::assertEquals([new MainViolation($extra, Violation::Unsigned)], $classification->violations);
    }

    public function testAPlainFastForwardToATaskHeadWithTwoUnsignedCommitsIsCleanWhenRecordedWithItsBase(): void
    {
        $base = $this->repository->commitEmpty('Main before the task');
        $this->repository->checkoutNewBranch('task');
        $this->repository->commitEmpty('First task commit');
        $head = $this->repository->commitEmpty('Second task commit');
        $this->fastForwardMainTo('task');

        $classification = $this->classify(new WatchedMain($base, [new RecordedMerge($head, $base)]));

        self::assertTrue($classification->isClean());
    }

    public function testAnUnsignedCommitOnMainBelowTheRecordedBaseOfAFastForwardIsUnsigned(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $unsigned = $this->repository->commitEmpty('Unsigned commit on main');
        $base = $this->repository->commitEmpty('Recorded base above the unsigned commit');
        $this->repository->checkoutNewBranch('task');
        $head = $this->repository->commitEmpty('Task commit');
        $this->fastForwardMainTo('task');

        $classification = $this->classify(new WatchedMain($seen, [new RecordedMerge($head, $base)]));

        self::assertEquals([new MainViolation($unsigned, Violation::Unsigned), new MainViolation($base, Violation::Unsigned)], $classification->violations);
    }

    public function testAFileNamedLikeTheRecordedBaseDoesNotChangeWhatTheMergeBroughtIn(): void
    {
        $base = $this->repository->commitEmpty('Main before the task');
        $head = $this->repository->commitFile($base, 'A file named like the base commit');

        self::assertSame([$head], $this->history()->broughtInBy(new RecordedMerge($head, $base)));
    }

    public function testAnUnsignedCommitMainAlreadyHeldStaysUnsignedWhenTheRepositoryIsCutShallowAtTheBaseWhileTheCoverageIsRead(): void
    {
        [$unsigned, $base, $main] = $this->taskBranchThatMergedMainAboveAnUnsignedCommit();
        $this->gitThatCutsShallowAtOnlyWhileTheCoverageIsRead($base);

        $classification = $this->classify($main);

        self::assertEquals([new MainViolation($unsigned, Violation::Unsigned)], $classification->violations);
    }

    public function testARepositoryLeftCutShallowAtTheBaseWhileTheCoverageIsReadIsRefused(): void
    {
        [, $base, $main] = $this->taskBranchThatMergedMainAboveAnUnsignedCommit();
        $this->gitThatLeavesTheRepositoryCutShallowAt($base);

        $this->expectException(MainNotRead::class);

        $this->classify($main);
    }

    public function testAnUnsignedRecordedBaseOnMainIsItselfUnsigned(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $unsigned = $this->repository->commitEmpty('Unsigned commit on main');
        $this->repository->checkoutNewBranch('task');
        $head = $this->repository->commitEmpty('Task commit');
        $this->fastForwardMainTo('task');

        $classification = $this->classify(new WatchedMain($seen, [new RecordedMerge($head, $unsigned)]));

        self::assertEquals([new MainViolation($unsigned, Violation::Unsigned)], $classification->violations);
    }

    public function testAnUnsignedCommitOnMainBelowARecordedMergeCommitIsUnsigned(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $unsigned = $this->repository->commitEmpty('Unsigned commit on main');
        $this->repository->checkoutNewBranch('task');
        $this->repository->commitEmpty('Task commit');
        $this->repository->checkout('main');
        $merge = $this->repository->merge('task');

        $classification = $this->classify(new WatchedMain($seen, [new RecordedMerge($merge, $unsigned)]));

        self::assertEquals([new MainViolation($unsigned, Violation::Unsigned)], $classification->violations);
    }

    public function testARecordedHeadTheRepositoryDoesNotHoldFailsTheRead(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('New commit on main');

        $this->expectException(ProcessFailedException::class);

        $this->classify(new WatchedMain($seen, [new RecordedMerge(str_repeat('e', 40), $seen)]));
    }

    public function testTheCommitsARecordedMergeBroughtInAreNotReadFromAShallowRepository(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $head = $this->repository->commitEmpty('Task commit');
        $this->repository->cutHistoryBelow($base);

        $this->expectException(MainNotRead::class);

        $this->history()->broughtInBy(new RecordedMerge($head, $base));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->path) {
            putenv('PATH='.$this->path);
        }
        if (null !== $this->bin) {
            new Filesystem()->remove($this->bin);
        }
        $this->repository->remove();
    }

    // Main moves on after the task branch forked, and the task branch then merges main, so main's own commit sits only on the merge's second parent; main's commit before the fast-forward is returned.
    private function taskBranchThatMergedMain(): string
    {
        $this->repository->commitEmpty('Fork point');
        $this->repository->checkoutNewBranch('task');
        $this->repository->commitEmpty('Task commit');
        $this->repository->checkout('main');
        $base = $this->repository->commitEmpty('Main moved on');
        $this->repository->checkout('task');
        $this->repository->merge('main');

        return $base;
    }

    // The unsigned commit sits on main below the base, and the task branch forked above it, so it is reachable from the recorded head and must stay outside the coverage only through the base; the unsigned commit, the base and the watched main are returned.
    /**
     * @return array{string, string, WatchedMain}
     */
    private function taskBranchThatMergedMainAboveAnUnsignedCommit(): array
    {
        $seen = $this->repository->commitEmpty('Seen');
        $unsigned = $this->repository->commitEmpty('Unsigned commit already on main');
        $this->repository->checkoutNewBranch('task');
        $this->repository->commitEmpty('Task commit');
        $this->repository->checkout('main');
        $base = $this->repository->commitEmpty('Main moved on');
        $this->repository->checkout('task');
        $head = $this->repository->merge('main');
        $this->fastForwardMainTo('task');

        return [$unsigned, $base, new WatchedMain($seen, [new RecordedMerge($head, $base)])];
    }

    // The cut is removed when the read ends, because only such a cut is one no later shallow check can see.
    private function gitThatCutsShallowAtOnlyWhileTheCoverageIsRead(string $entry): void
    {
        $this->gitThatRunsOnTheCoverageRead($this->cutShallowAt($entry)."\n        ".$this->realGit()." \"\$@\"\n        status=\$?\n        rm -f ".$this->shallowFile()."\n        exit \$status");
    }

    private function gitThatLeavesTheRepositoryCutShallowAt(string $entry): void
    {
        $this->gitThatRunsOnTheCoverageRead($this->cutShallowAt($entry)."\n        exec ".$this->realGit().' "$@"');
    }

    private function cutShallowAt(string $entry): string
    {
        return sprintf("printf '%%s\\n' %s > %s", escapeshellarg($entry), $this->shallowFile());
    }

    private function realGit(): string
    {
        return escapeshellarg((string) new ExecutableFinder()->find('git'));
    }

    private function shallowFile(): string
    {
        return escapeshellarg($this->repository->path.'/.git/shallow');
    }

    // A git in front of the real one, because only that forces the cut between the shallow check and the coverage read.
    private function gitThatRunsOnTheCoverageRead(string $coverage): void
    {
        $git = $this->realGit();
        $this->bin = sys_get_temp_dir().'/intakt-watchdog-git-'.bin2hex(random_bytes(6));
        mkdir($this->bin);
        file_put_contents($this->bin.'/git', "#!/bin/sh\ncase \" \$* \" in\n    *\" --not \"*)\n        ".$coverage."\n        ;;\n    *) exec ".$git." \"\$@\" ;;\nesac\n");
        chmod($this->bin.'/git', 0o700);
        $this->path = (string) getenv('PATH');
        putenv('PATH='.$this->bin.':'.$this->path);
    }

    private function fastForwardMainTo(string $branch): void
    {
        $this->repository->checkout('main');
        $this->repository->resetTo($branch);
    }

    private function classify(WatchedMain $main): Classification
    {
        return new CommitClassifier($this->history())->classify($main);
    }

    private function history(): MainHistory
    {
        return new MainHistory(new HardenedGit(), $this->repository->path, [], new Filesystem());
    }
}
