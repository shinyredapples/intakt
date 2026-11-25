<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge\Infrastructure;

use Intakt\Core\Git\Contract\ArchivedHead;
use Intakt\Core\Git\Contract\ArchivedHeadPush;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RemoteWrite;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\UseCase\PushBranch\BranchGit;
use Intakt\Core\Git\UseCase\PushBranch\GuardedRun;
use Intakt\Core\Git\UseCase\PushBranch\PushArchivedHead;
use Intakt\Core\Git\UseCase\PushBranch\PushLockName;
use Intakt\Plugin\Forge\Contract\BranchRestore;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalBranchRestore;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Git\UseCase\PushBranch\ProgramOnPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// Apart from LocalForgeBranchesTest, because these tests need a push that fails at a chosen point, and that class restores through the real archive only.
#[CoversClass(LocalBranchRestore::class)]
#[UsesClass(ArchivedHead::class)]
#[UsesClass(BranchGit::class)]
#[UsesClass(BranchHead::class)]
#[UsesClass(BranchNotPushed::class)]
#[UsesClass(BranchRestore::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(ForgeWriteFailed::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(GuardedRun::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(LocalRepository::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(PushArchivedHead::class)]
#[UsesClass(PushLockName::class)]
final class LocalBranchRestoreTest extends TestCase
{
    private const string LINK = 'symbolic-ref refs/heads/intakt/feature refs/heads/intakt/';
    private const string LEFT = 'Nothing was removed; the refs new since the push began that hold the archived head are ';
    private const string NONE_LEFT = 'Nothing was removed, and no ref new since the push began holds the archived head.';

    private TemporaryRepository $forge;

    private string $head;

    private ProgramOnPath $programs;

    public function testARestoreRefusedBeforeItsPushNeverDeletesABranchThatExistedBefore(): void
    {
        $push = $this->aPushThat(['symbolic-ref', 'refs/heads/intakt/feature', 'refs/heads/main'], BranchNotPushed::because('the remote refused'));

        self::assertSame('The branch "intakt/feature" was not restored: The branch was not pushed: the remote refused', $this->refusalOf($push));
        self::assertSame($this->head, $this->forge->objectAt('refs/heads/main'));
    }

    public function testABranchAtTheArchivedHeadSurvivesWhenTheBranchIsPointedAtItBeforeThePushReadsTheRemote(): void
    {
        $archived = $this->anArchivedHeadThatABranchOfTheForgeKeeps();
        $this->programs->runFirstOn('git', 'ls-remote', $this->inTheForge(self::LINK.'keep'));

        self::assertSame('The branch "intakt/feature" was not restored: The branch was not pushed: the branch intakt/feature exists on the remote, and a restore never overwrites a branch', $this->refusalOf($this->theRealPush(), $archived));
        self::assertSame($archived, $this->forge->objectAt('refs/heads/intakt/keep'));
    }

    public function testABranchPointedAtAMissingBranchBeforeThePushAndAtAnExistingOneAfterItLeavesAndNamesTheWrittenBranchAndKeepsTheExistingOne(): void
    {
        $archived = $this->anArchivedHeadThatABranchOfTheForgeKeeps();
        $this->programs->runFirstOn('git', 'push', sprintf('%s; %s "$@"; status=$?; %s; exit $status', $this->inTheForge(self::LINK.'other'), $this->realGit(), $this->inTheForge(self::LINK.'keep')));

        self::assertSame('The branch "intakt/feature" was not restored: refs/heads/intakt/feature is no plain branch at the archived head after the push, so another writer changed it during the restore. '.self::LEFT.'refs/heads/intakt/feature, refs/heads/intakt/other.', $this->refusalOf($this->theRealPush(), $archived));
        self::assertSame($archived, $this->forge->objectAt('refs/heads/intakt/keep'));
        self::assertSame($archived, $this->forge->objectAt('refs/heads/intakt/other'));
    }

    public function testABranchAnotherWriterPutsInPlaceOfThePushedOneIsNamedWithItAndNothingIsDeleted(): void
    {
        $archived = $this->anArchivedHeadThatABranchOfTheForgeKeeps();
        $this->programs->runFirstOn('git', 'push', sprintf('%s "$@"; status=$?; %s; %s; exit $status', $this->realGit(), $this->inTheForge('branch intakt/mine '.$archived), $this->inTheForge(self::LINK.'mine')));

        self::assertSame('The branch "intakt/feature" was not restored: refs/heads/intakt/feature is no plain branch at the archived head after the push, so another writer changed it during the restore. '.self::LEFT.'refs/heads/intakt/feature, refs/heads/intakt/mine.', $this->refusalOf($this->theRealPush(), $archived));
        self::assertSame($archived, $this->forge->objectAt('refs/heads/intakt/keep'));
        self::assertSame($archived, $this->forge->objectAt('refs/heads/intakt/mine'));
    }

    public function testATagTheBranchIsPointedAtBeforeThePushIsNamedAndLeft(): void
    {
        $archived = $this->anArchivedHeadThatABranchOfTheForgeKeeps();
        $this->programs->runFirstOn('git', 'push', $this->inTheForge('symbolic-ref refs/heads/intakt/feature refs/tags/missing'));

        self::assertSame('The branch "intakt/feature" was not restored: refs/heads/intakt/feature is no plain branch at the archived head after the push, so another writer changed it during the restore. '.self::LEFT.'refs/heads/intakt/feature, refs/tags/missing.', $this->refusalOf($this->theRealPush(), $archived));
        self::assertSame($archived, $this->forge->objectAt('refs/heads/intakt/keep'));
        self::assertSame($archived, $this->forge->objectAt('refs/tags/missing'));
    }

    public function testABranchMadeWhileAPushFailedWithoutReportingANewBranchIsNamedAndNotRemoved(): void
    {
        $push = $this->aPushThat(['branch', 'intakt/other', $this->head], BranchNotPushed::because('git push failed', write: RemoteWrite::Unknown));

        self::assertSame('The branch "intakt/feature" was not restored: The branch was not pushed: git push failed. '.self::LEFT.'refs/heads/intakt/other.', $this->refusalOf($push));
        self::assertSame($this->head, $this->forge->objectAt('refs/heads/intakt/other'));
    }

    public function testTheOneBranchMadeDuringAPushThatReportedItsNewBranchAndThenFailedIsNamedAndNotRemoved(): void
    {
        $push = $this->aPushThat(['branch', 'intakt/other', $this->head], BranchNotPushed::because('the remote branch is not at the archived head', write: RemoteWrite::Created));

        self::assertSame('The branch "intakt/feature" was not restored: The branch was not pushed: the remote branch is not at the archived head. '.self::LEFT.'refs/heads/intakt/other.', $this->refusalOf($push));
        self::assertSame($this->head, $this->forge->objectAt('refs/heads/intakt/other'));
    }

    public function testTwoBranchesMadeAtTheArchivedHeadDuringAPushThatCreatedOneAreNamedAndNotRemoved(): void
    {
        $push = $this->aPushThat(['fetch', '--quiet', '.', $this->head.':refs/heads/intakt/other', $this->head.':refs/heads/intakt/second'], BranchNotPushed::because('the remote branch is not at the archived head', write: RemoteWrite::Created));

        self::assertSame('The branch "intakt/feature" was not restored: The branch was not pushed: the remote branch is not at the archived head. '.self::LEFT.'refs/heads/intakt/other, refs/heads/intakt/second.', $this->refusalOf($push));
        self::assertSame($this->head, $this->forge->objectAt('refs/heads/intakt/other'));
        self::assertSame($this->head, $this->forge->objectAt('refs/heads/intakt/second'));
    }

    public function testAPushThatFailedAfterItCreatedTheBranchItselfIsRefusedAndNamesAndKeepsTheBranch(): void
    {
        $push = $this->aPushThat(['branch', 'intakt/feature', $this->head], BranchNotPushed::because('git ls-remote failed with exit code 128', write: RemoteWrite::Created));

        self::assertSame('The branch "intakt/feature" was not restored: The branch was not pushed: git ls-remote failed with exit code 128. '.self::LEFT.'refs/heads/intakt/feature.', $this->refusalOf($push));
        self::assertSame($this->head, $this->forge->objectAt('refs/heads/intakt/feature'));
    }

    public function testAPushThatFailedAndLeftNoNewRefAtTheArchivedHeadSaysSo(): void
    {
        $push = $this->aPushThat(['branch', 'intakt/unrelated', $this->head], BranchNotPushed::because('git push failed', write: RemoteWrite::Unknown));

        self::assertSame('The branch "intakt/feature" was not restored: The branch was not pushed: git push failed. '.self::NONE_LEFT, $this->refusalOf($push, str_repeat('a', 40)));
        self::assertSame($this->head, $this->forge->objectAt('refs/heads/intakt/unrelated'));
    }

    public function testAPushThatReportsSuccessWithoutABranchAtTheArchivedHeadIsNoRestore(): void
    {
        self::assertSame('The branch "intakt/feature" was not restored: refs/heads/intakt/feature is no plain branch at the archived head after the push, so another writer changed it during the restore. '.self::NONE_LEFT, $this->refusalOf(self::createStub(ArchivedHeadPush::class)));
    }

    public function testNothingIsPushedWhenTheBranchesCannotBeListedBeforeThePush(): void
    {
        $push = $this->createMock(ArchivedHeadPush::class);
        $push->expects(self::never())->method('push');
        $this->programs->runFirstOn('git', 'for-each-ref', 'echo broken >&2; exit 128');

        self::assertSame('The branch "intakt/feature" was not restored: git for-each-ref failed before the push: broken', $this->refusalOf($push));
    }

    public function testARestoreWhoseBranchesCannotBeListedAfterThePushIsRefusedAndSaysABranchMayBeLeft(): void
    {
        $mark = escapeshellarg($this->archives().'-listed');
        $this->programs->runFirstOn('git', 'for-each-ref', sprintf('test ! -e %s || { echo broken >&2; exit 128; }; touch %s', $mark, $mark));

        try {
            self::assertSame('The branch "intakt/feature" was not restored: git for-each-ref failed after the push, so a ref the push wrote may be left: broken',$this->refusalOf(self::createStub(ArchivedHeadPush::class)));
        } finally {
            new Filesystem()->remove($this->archives().'-listed');
        }
    }

    protected function setUp(): void
    {
        $this->forge = new TemporaryRepository();
        $this->head = $this->forge->commitFile('README.md', "base\n");
        $this->programs = new ProgramOnPath();
    }

    protected function tearDown(): void
    {
        $this->programs->remove();
        $this->forge->remove();
        new Filesystem()->remove($this->archives());
    }

    // A push that runs one git command in the forge, as another writer would during it, and then fails.
    /**
     * @param list<string> $command
     */
    private function aPushThat(array $command, BranchNotPushed $failure): ArchivedHeadPush
    {
        $push = self::createStub(ArchivedHeadPush::class);
        $push->method('push')->willReturnCallback(function () use ($command, $failure): never {
            $this->git(...$command);

            throw $failure;
        });

        return $push;
    }

    private function refusalOf(ArchivedHeadPush $push, ?string $head = null): string
    {
        try {
            new LocalBranchRestore(new LocalRepository($this->forge->path, 'main'), $push, new HardenedGit())->restore(new BranchRestore('alpha', 'intakt/feature', new CommitId($head ?? $this->head)));
        } catch (ForgeWriteFailed $refused) {
            return $refused->getMessage();
        }

        self::fail('The restore was not refused.');
    }

    // A commit only the archive made, which the forge's branch intakt/keep holds too, so a restore that removes a ref at the archived head could remove that branch.
    private function anArchivedHeadThatABranchOfTheForgeKeeps(): string
    {
        $archive = $this->archives().'/alpha.git';
        $this->git('init', '--quiet', '--bare', $archive);
        $archived = $this->forge->commitFile('src/New.php', "<?php\n");
        $this->git('push', '--quiet', $archive, $archived.':refs/intakt/archive/feature');
        $this->git('branch', 'intakt/keep', $archived);
        $this->git('reset', '--quiet', '--hard', $this->head);

        return $archived;
    }

    private function inTheForge(string $command): string
    {
        return sprintf('%s -C %s %s', $this->realGit(), escapeshellarg($this->forge->path), $command);
    }

    private function theRealPush(): PushArchivedHead
    {
        return new PushArchivedHead(new BranchGit(new HardenedGit(), new GuardedRun()), new LockFactory(new InMemoryStore()), new Mirrors($this->archives()));
    }

    private function realGit(): string
    {
        return escapeshellarg(new ExecutableFinder()->find('git') ?? self::fail('git is not on PATH.'));
    }

    private function archives(): string
    {
        return $this->forge->path.'-archives';
    }

    private function git(string ...$command): void
    {
        new Process(['git', ...$command], $this->forge->path, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false])->mustRun();
    }
}
