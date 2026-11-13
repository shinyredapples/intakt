<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\MainVerdict;
use Intakt\Core\Watchdog\Contract\OwnerKeys;
use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Contract\ViolationKind;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use Intakt\Core\Watchdog\Domain\SeenCommit;
use Intakt\Core\Watchdog\Domain\SignatureStatus;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use Intakt\Core\Watchdog\UseCase\CheckMain\CheckMain;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Watchdog\SigningKeys;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(CheckMain::class)]
#[CoversClass(MainHistory::class)]
#[CoversClass(CommitClassifier::class)]
#[CoversClass(SignatureStatus::class)]
#[CoversClass(MainNotRead::class)]
#[CoversClass(MainVerdict::class)]
#[UsesClass(SeenCommit::class)]
#[UsesClass(Violation::class)]
#[UsesClass(WatchedMain::class)]
#[UsesClass(OwnerKeys::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
final class CheckMainTest extends TestCase
{
    private TemporaryRepository $repository;

    private SigningKeys $keys;

    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->keys = new SigningKeys();
    }

    protected function tearDown(): void
    {
        $this->repository->remove();
        $this->keys->remove();
    }

    public function testAnUnsignedCommitIsAViolation(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $unsigned = $this->repository->commitEmpty('Straight to main');

        $verdict = $this->whenMainIsChecked($base, []);

        self::assertFalse($verdict->isClean());
        self::assertSame([ViolationKind::Unsigned], $this->kindsOf($verdict));
        self::assertSame($unsigned, $verdict->violations[0]->commit);
        self::assertSame($unsigned, $verdict->head);
    }

    public function testACommitSignedByAKeyTheOwnerDidNotRegisterIsAViolation(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $this->repository->commitEmptySignedWith($this->keys->privateKeyOf('stranger'), 'Foreign');

        $verdict = $this->whenMainIsChecked($base, []);

        self::assertSame([ViolationKind::UnknownKey], $this->kindsOf($verdict));
    }

    public function testACommitSignedByTheOwnersKeyPasses(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $this->repository->commitEmptySignedWith($this->keys->privateKeyOf('owner'), 'Owner push');

        self::assertTrue($this->whenMainIsChecked($base, [])->isClean());
    }

    public function testAMergeIntaktRecordedPassesWithoutASignature(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $this->repository->checkoutNewBranch('feat/task');
        $this->repository->commitFile('a.txt', 'one');
        $this->repository->checkout('main');
        $merge = $this->repository->merge('feat/task');

        $verdict = $this->whenMainIsChecked($base, [$merge]);

        self::assertTrue($verdict->isClean(), 'the commits the merge brought in are covered by it');
    }

    public function testAMergeNobodyRecordedIsAViolation(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $this->repository->checkoutNewBranch('feat/task');
        $this->repository->commitFile('a.txt', 'one');
        $this->repository->checkout('main');
        $merge = $this->repository->merge('feat/task');

        $verdict = $this->whenMainIsChecked($base, []);

        self::assertSame([[$merge, ViolationKind::Unsigned]], array_map(static fn (Violation $violation): array => [$violation->commit, $violation->kind], $verdict->violations));
    }

    public function testACommitThatAnEarlierCheckPassedIsNotJudgedAgain(): void
    {
        $this->repository->commitEmpty('Straight to main');
        $checked = $this->repository->commitEmptySignedWith($this->keys->privateKeyOf('owner'), 'Owner push');

        self::assertTrue($this->whenMainIsChecked($checked, [])->isClean());
    }

    public function testAMainNeverCheckedIsJudgedFromItsFirstCommit(): void
    {
        $first = $this->repository->commitEmpty('Root');

        $verdict = $this->whenMainIsChecked(null, []);

        self::assertSame($first, $verdict->violations[0]->commit);
    }

    public function testARewriteOfMainsHistoryIsAViolation(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $checked = $this->repository->commitEmptySignedWith($this->keys->privateKeyOf('owner'), 'Checked');
        $this->repository->moveHeadTo($base);
        $this->repository->commitEmptySignedWith($this->keys->privateKeyOf('owner'), 'Rewritten');

        $verdict = $this->whenMainIsChecked($checked, []);

        self::assertSame([ViolationKind::HistoryRewritten], $this->kindsOf($verdict));
        self::assertSame($checked, $verdict->violations[0]->commit);
    }

    public function testAHeadThatTheRepositoryNoLongerHoldsCountsAsRewritten(): void
    {
        $this->repository->commitEmpty('Base');

        $verdict = $this->whenMainIsChecked(str_repeat('a', 40), []);

        self::assertSame([ViolationKind::HistoryRewritten], $this->kindsOf($verdict));
    }

    public function testARepositoryWhoseHeadNamesNoBranchIsNotRead(): void
    {
        $this->repository->commitEmpty('Base');
        $this->repository->checkoutDetached('HEAD');

        $this->expectException(MainNotRead::class);

        $this->whenMainIsChecked(null, []);
    }

    public function testAMainWithAShallowHistoryIsNotReadEvenWhenTheCommitsAboveTheCutAreClean(): void
    {
        $this->repository->commitEmpty('Unsigned ancestor');
        $this->repository->checkoutNewBranch('feat/task');
        $this->repository->commitFile('task.txt', 'Task');
        $this->repository->checkout('main');
        $merge = $this->repository->merge('feat/task');
        $this->repository->cutHistoryBelow($merge);

        $this->expectException(MainNotRead::class);
        $this->expectExceptionMessage('shallow');

        $this->whenMainIsChecked(null, [$merge]);
    }

    public function testARepositoryNamedByARelativePathIsReadLikeItsAbsolutePath(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $unsigned = $this->repository->commitEmpty('Straight to main');
        $before = (string) getcwd();
        chdir($this->repository->path);

        try {
            $verdict = $this->check()->of('.git', $this->watched($base, []));
        } finally {
            chdir($before);
        }

        self::assertSame([$unsigned], array_map(static fn (Violation $violation): string => $violation->commit, $verdict->violations));
    }

    public function testAFolderThatIsNoRepositoryIsNotRead(): void
    {
        $this->expectException(MainNotRead::class);

        (void) $this->check()->of($this->keys->directory, $this->watched(null, []));
    }

    /**
     * @param list<string> $recorded
     */
    private function whenMainIsChecked(?string $since, array $recorded): MainVerdict
    {
        return $this->check()->of($this->repository->path.'/.git', $this->watched($since, $recorded));
    }

    /**
     * @param list<string> $recorded
     */
    private function watched(?string $since, array $recorded): WatchedMain
    {
        return new WatchedMain($since, $recorded, OwnerKeys::of([$this->keys->publicKeyOf('owner')]));
    }

    private function check(): CheckMain
    {
        return new CheckMain(new MainHistory(new HardenedGit(), new Filesystem()), new CommitClassifier());
    }

    /**
     * @return list<ViolationKind>
     */
    private function kindsOf(MainVerdict $verdict): array
    {
        return array_map(static fn (Violation $violation): ViolationKind => $violation->kind, $verdict->violations);
    }
}
