<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\ArchivedHead;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\MirroredHead;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\RemoteCredential;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\UseCase\ArchiveBranch\MirrorBranchArchive;
use Intakt\Core\Git\UseCase\PushBranch\BranchGit;
use Intakt\Core\Git\UseCase\PushBranch\GuardedRun;
use Intakt\Core\Git\UseCase\PushBranch\PushArchivedHead;
use Intakt\Core\Git\UseCase\PushBranch\PushLockName;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// A forge repository, its mirror and the archive in temporary folders, because a restore is only proven when git really collected the commit and the push brings it back.
#[CoversClass(PushArchivedHead::class)]
#[CoversClass(PushLockName::class)]
#[CoversClass(BranchGit::class)]
#[UsesClass(ArchivedBranch::class)]
#[UsesClass(ArchivedHead::class)]
#[UsesClass(BranchHead::class)]
#[UsesClass(BranchNotPushed::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(GuardedRun::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(MirroredHead::class)]
#[UsesClass(MirrorBranchArchive::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(ProjectNameRefused::class)]
#[CoversClass(RemoteCredential::class)]
#[UsesClass(UpdateMirror::class)]
final class PushArchivedHeadTest extends TestCase
{
    private string $root;

    private TemporaryRepository $forge;

    private string $base;

    private string $topicHead;

    private LockFactory $locks;

    public function testABranchIsRestoredAfterTheForgeCollectedItsCommit(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();
        self::assertFalse($this->forgeHolds($this->topicHead));

        $this->restorer()->push($this->head('topic', $this->topicHead), $this->forge->path);

        self::assertSame($this->topicHead, $this->git($this->forge->path, 'rev-parse', 'refs/heads/topic'));
        self::assertSame('commit', $this->git($this->forge->path, 'cat-file', '-t', $this->topicHead));
    }

    public function testARestoreIsRefusedWhenTheBranchExistsAndTheBranchKeepsItsHead(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();
        $this->git($this->forge->path, 'branch', 'topic', $this->base);

        $this->expectRefusal('The branch was not pushed: the branch topic exists on the remote, and a restore never overwrites a branch');
        try {
            $this->restorer()->push($this->head('topic', $this->topicHead), $this->forge->path);
        } finally {
            self::assertSame($this->base, $this->git($this->forge->path, 'rev-parse', 'refs/heads/topic'));
        }
    }

    public function testABranchMadeBetweenTheCheckAndThePushIsNotMovedEvenWhenTheRestoreWouldFastForwardIt(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();
        $git = new ExecutableFinder()->find('git') ?? self::fail('git is not on PATH.');
        $programs = new ProgramOnPath();
        $programs->runFirstOn('git', 'push', sprintf('%s -C %s branch topic %s', escapeshellarg($git), escapeshellarg($this->forge->path), $this->base));

        try {
            $this->restorer()->push($this->head('topic', $this->topicHead), $this->forge->path);
            self::fail('A branch made before the push must not be moved by it.');
        } catch (BranchNotPushed $refused) {
            self::assertStringStartsWith('The branch was not pushed: the remote refused the restore of topic (git push exited with ', $refused->getMessage());
        } finally {
            $programs->remove();
        }

        self::assertSame($this->base, $this->git($this->forge->path, 'rev-parse', 'refs/heads/topic'));
    }

    public function testABranchAnotherWriterMadeAtTheArchivedHeadDuringTheRestoreIsRefused(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();
        $this->git($this->forge->path, 'fetch', '--quiet', $this->archivePath(), $this->topicHead);
        $git = new ExecutableFinder()->find('git') ?? self::fail('git is not on PATH.');
        $programs = new ProgramOnPath();
        $programs->runFirstOn('git', 'push', sprintf('%s -C %s update-ref refs/heads/topic %s', escapeshellarg($git), escapeshellarg($this->forge->path), $this->topicHead));

        try {
            $this->expectRefusal('The branch was not pushed: the push did not create the branch topic, so another writer made it during the restore');
            $this->restorer()->push($this->head('topic', $this->topicHead), $this->forge->path);
        } finally {
            $programs->remove();
        }
    }

    public function testTheLockIsFreeAgainAfterARestoreThatLanded(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();

        $this->restorer()->push($this->head('topic', $this->topicHead), $this->forge->path);

        self::assertTrue($this->locks->createLock(PushLockName::of('alpha', 'topic'), autoRelease: false)->acquire(false));
    }

    public function testTheCredentialReachesGitAsAHeaderInItsEnvironmentAndNeverOnItsCommandLine(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();
        $calls = $this->root.'/calls';
        $programs = new ProgramOnPath();
        $programs->runFirstOn('git', 'push', sprintf('{ echo "$@"; env; } >> %s', escapeshellarg($calls)));

        try {
            $this->restorer()->push($this->head('topic', $this->topicHead), $this->forge->path, new RemoteCredential('x-access-token', 'held-for-the-push'));
        } finally {
            $programs->remove();
        }

        $recorded = (string) file_get_contents($calls);
        self::assertStringNotContainsString('held-for-the-push', $recorded);
        self::assertStringContainsString('=Authorization: Basic '.base64_encode('x-access-token:held-for-the-push'), $recorded);
        self::assertStringContainsString('=http.extraHeader', $recorded);
    }

    public function testNoCredentialOfTheRemoteIsInTheTraceOfARefusedRestore(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();
        $remote = 'https://x-access-token:in-the-address@example.invalid/acme/widgets.git';
        $this->git($this->archivePath(), 'config', 'url.file:///nowhere.insteadOf', $remote);
        $ignored = ini_set('zend.exception_ignore_args', '0');

        try {
            $this->restorer()->push($this->head('topic', $this->topicHead), $remote, new RemoteCredential('x-access-token', 'held-for-the-push'));
            self::fail('A rewritten remote must not be pushed to.');
        } catch (BranchNotPushed $refused) {
            $trace = print_r($refused->getTrace(), true);
            self::assertStringNotContainsString('in-the-address', $trace);
            self::assertStringNotContainsString('held-for-the-push', $trace);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignored);
        }
    }

    public function testAnAbbreviatedIdOfACommitInASha256ArchiveIsRefusedBeforeAnythingIsPushed(): void
    {
        $forge = new TemporaryRepository('sha256');
        $forge->commitFile('a.txt', 'one');
        $forge->checkoutNewBranch('topic');
        $head = $forge->commitFile('b.txt', 'two');
        $forge->checkout('main');
        $this->git($forge->path, 'clone', '--quiet', '--bare', $forge->path, $this->archivePath());
        $forge->deleteBranch('topic');

        try {
            $this->expectRefusal(sprintf('The branch was not pushed: %s is no full commit id of the archive', substr($head, 0, 40)));
            $this->restorer()->push($this->head('topic', substr($head, 0, 40)), $forge->path);
        } finally {
            self::assertSame('', $this->git($forge->path, 'branch', '--list', 'topic'));
            $forge->remove();
        }
    }

    public function testARestoreIsRefusedWhileAPushOfTheSameBranchHoldsItsLock(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();
        $held = $this->locks->createLock(PushLockName::of('alpha', 'topic'), autoRelease: false);
        $held->acquire(false);

        $this->expectRefusal('The branch was not pushed: another push of this branch is running');
        try {
            $this->restorer()->push($this->head('topic', $this->topicHead), $this->forge->path);
        } finally {
            self::assertSame('', $this->git($this->forge->path, 'branch', '--list', 'topic'));
        }
    }

    public function testARestoreIsRefusedWhenTheArchivesConfigRewritesTheRemote(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();
        $elsewhere = new TemporaryRepository();
        $this->git($this->archivePath(), 'config', 'url.'.$elsewhere->path.'.insteadOf', $this->forge->path);

        try {
            $this->restorer()->push($this->head('topic', $this->topicHead), $this->forge->path);
            self::fail('A rewritten remote must not be pushed to.');
        } catch (BranchNotPushed $refused) {
            self::assertStringContainsString('rewrites the remote\'s address', $refused->getMessage());
        } finally {
            $elsewhere->remove();
        }

        self::assertSame('', $this->git($this->forge->path, 'branch', '--list', 'topic'));
    }

    public function testACommitTheArchiveDoesNotHoldIsNotPushed(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();
        $unarchived = $this->forge->commitFile('c.txt', 'three');

        $this->expectRefusal('The branch was not pushed: git cat-file failed');

        $this->restorer()->push($this->head('topic', $unarchived), $this->forge->path);
    }

    public function testATreeTheArchiveHoldsIsNotPushedAsABranchHead(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();
        $tree = $this->git($this->archivePath(), 'rev-parse', $this->topicHead.'^{tree}');

        $this->expectRefusal('The branch was not pushed: the archive holds no commit '.$tree);

        $this->restorer()->push($this->head('topic', $tree), $this->forge->path);
    }

    public function testABranchNameGitRefusesIsNotPushed(): void
    {
        $this->archiveTopicAndDeleteItOnTheForge();

        $this->expectRefusal('The branch was not pushed: git check-ref-format failed');

        $this->restorer()->push($this->head('topic..old', $this->topicHead), $this->forge->path);
    }

    public function testAProjectNameThatLeavesTheArchiveFolderIsNotPushed(): void
    {
        $this->expectException(BranchNotPushed::class);

        $this->restorer()->push(new ArchivedHead('../alpha', new BranchHead('topic', $this->topicHead)), $this->forge->path);
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-restore-'.bin2hex(random_bytes(6));
        $this->locks = new LockFactory(new InMemoryStore());
        $this->forge = new TemporaryRepository();
        $this->base = $this->forge->commitFile('a.txt', 'one');
        $this->forge->checkoutNewBranch('topic');
        $this->topicHead = $this->forge->commitFile('b.txt', 'two');
        $this->forge->checkout('main');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        $this->forge->remove();
    }

    private function archiveTopicAndDeleteItOnTheForge(): void
    {
        new UpdateMirror(new HardenedGit(), new NetworkGit(new RecordingClock(), new NullLogger()), new Filesystem(), $this->locks, new Mirrors($this->root.'/mirrors'))->update('alpha', $this->forge->path);
        $clock = new MockClock(new DateTimeImmutable('2026-11-24 09:30:05', new DateTimeZone('UTC')));
        new MirrorBranchArchive(new HardenedGit(), new Filesystem(), $this->locks, $clock, new Mirrors($this->root.'/mirrors'), new Mirrors($this->root.'/archive'))
            ->archive(new MirroredHead('alpha', new BranchHead('topic', $this->topicHead)));

        $this->forge->deleteBranch('topic');
        // The reflogs too, because HEAD's reflog still names the commit and would keep it from being collected.
        $this->git($this->forge->path, 'reflog', 'expire', '--expire=now', '--all');
        $this->git($this->forge->path, 'gc', '--quiet', '--prune=now');
    }

    private function forgeHolds(string $commit): bool
    {
        return 0 === new Process(['git', 'cat-file', '-e', $commit], $this->forge->path, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false])->run();
    }

    private function restorer(): PushArchivedHead
    {
        return new PushArchivedHead(new BranchGit(new HardenedGit(), new GuardedRun()), $this->locks, new Mirrors($this->root.'/archive'));
    }

    private function head(string $branch, string $commit): ArchivedHead
    {
        return new ArchivedHead('alpha', new BranchHead($branch, $commit));
    }

    private function expectRefusal(string $message): void
    {
        $this->expectExceptionObject(new BranchNotPushed($message));
    }

    private function archivePath(): string
    {
        return $this->root.'/archive/alpha.git';
    }

    private function git(string $directory, string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $directory, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
