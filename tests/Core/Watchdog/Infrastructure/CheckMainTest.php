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

    #[Override]
    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->repository->remove();
    }

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
