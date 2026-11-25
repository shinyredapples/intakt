<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\ArchiveBranch;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchNotArchived;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\MirroredHead;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\UseCase\ArchiveBranch\DeleteBranchAfterArchive;
use Intakt\Core\Git\UseCase\ArchiveBranch\MirrorBranchArchive;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeBranches;
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
use Symfony\Component\Process\Process;

#[CoversClass(MirrorBranchArchive::class)]
#[CoversClass(DeleteBranchAfterArchive::class)]
#[CoversClass(BranchNotArchived::class)]
#[UsesClass(ArchivedBranch::class)]
#[UsesClass(BranchHead::class)]
#[UsesClass(MirroredHead::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(ProjectNameRefused::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(UpdateMirror::class)]
#[UsesClass(CommitId::class)]
#[UsesClass(ForgeBranch::class)]
final class MirrorBranchArchiveTest extends TestCase
{
    private string $root;

    private TemporaryRepository $remote;

    private string $topicHead;

    public function testAnArchivedBranchHeadIsKeptUnderAUtcMomentAndTheBranchName(): void
    {
        $this->mirror();

        $archived = $this->archiver()->archive($this->head('topic', $this->topicHead));

        self::assertSame('refs/intakt/archive/20261124T093005Z/topic', $archived->ref);
        self::assertSame($this->topicHead, $this->git($this->archivePath(), 'rev-parse', $archived->ref));
        self::assertSame('2026-11-24T09:30:05+00:00', $archived->archivedAt->format('c'));
    }

    public function testAnArchiveIsStillListedAfterTheMirrorWasRemovedAndClonedAgain(): void
    {
        $this->mirror();
        $archived = $this->archiver()->archive($this->head('topic', $this->topicHead));
        new Filesystem()->remove($this->root.'/mirrors');
        $this->remote->deleteBranch('topic');

        $this->mirror();

        self::assertSame('', $this->git($this->root.'/mirrors/alpha.git', 'branch', '--list', 'topic'));
        self::assertSame($archived->ref, $this->git($this->archivePath(), 'for-each-ref', '--format=%(refname)', 'refs/intakt/archive/'));
        self::assertSame('commit', $this->git($this->archivePath(), 'cat-file', '-t', $this->topicHead));
    }

    public function testARefShapedLikeAnArchiveOnTheRemoteIsNotSeenInTheArchive(): void
    {
        $this->git($this->remote->path, 'update-ref', 'refs/intakt/archive/20200101T000000Z/old', $this->topicHead);
        $this->mirror();

        $archived = $this->archiver()->archive($this->head('topic', $this->topicHead));

        self::assertSame($archived->ref, $this->git($this->archivePath(), 'for-each-ref', '--format=%(refname)', 'refs/intakt/archive/'));
    }

    public function testTheArchiveLivesOutsideTheMirrorAndHoldsNoRemote(): void
    {
        $this->mirror();

        $this->archiver()->archive($this->head('topic', $this->topicHead));

        self::assertStringNotContainsString('[remote', (string) file_get_contents($this->archivePath().'/config'));
        self::assertSame('', $this->git($this->root.'/mirrors/alpha.git', 'for-each-ref', 'refs/intakt/'));
    }

    public function testAHeadTheMirrorDoesNotHoldIsNotArchived(): void
    {
        $this->mirror();

        try {
            $this->archiver()->archive($this->head('topic', str_repeat('a', 40)));
            self::fail('The head is not in the mirror.');
        } catch (BranchNotArchived) {
            self::assertSame('', $this->git($this->archivePath(), 'for-each-ref', 'refs/intakt/'));
        }
    }

    public function testAProjectWithoutAMirrorIsNotArchived(): void
    {
        $this->expectException(BranchNotArchived::class);

        $this->archiver()->archive($this->head('topic', $this->topicHead));
    }

    public function testAProjectNameThatLeavesTheArchiveDirectoryIsRefused(): void
    {
        $this->expectException(BranchNotArchived::class);

        $this->archiver()->archive(new MirroredHead('../escape', new BranchHead('topic', $this->topicHead)));
    }

    public function testAnArchiveRefThatAlreadyExistsIsNeverMoved(): void
    {
        $this->mirror();
        $this->archiver()->archive($this->head('topic', $this->topicHead));

        $this->expectException(BranchNotArchived::class);

        $this->archiver()->archive($this->head('topic', $this->remote->objectAt('main')));
    }

    public function testAFailedArchiveDeletesNothing(): void
    {
        $this->mirror();
        $forge = $this->createMock(ForgeBranches::class);
        $forge->expects(self::never())->method('delete');
        $branch = new ForgeBranch('topic', new CommitId(str_repeat('a', 40)), false, false, false);

        try {
            new DeleteBranchAfterArchive($this->archiver(), $forge)->delete('alpha', $branch);
            self::fail('The head is not in the mirror.');
        } catch (BranchNotArchived) {
            self::assertSame('', $this->git($this->archivePath(), 'for-each-ref', 'refs/intakt/'));
        }
    }

    public function testABranchIsDeletedOnlyAfterItsArchiveRefExists(): void
    {
        $this->mirror();
        $archive = $this->archivePath();
        $seen = [];
        $forge = $this->createMock(ForgeBranches::class);
        $forge->expects(self::once())->method('delete')->willReturnCallback(function () use ($archive, &$seen): void {
            $seen = explode("\n", $this->git($archive, 'for-each-ref', '--format=%(refname)', 'refs/intakt/archive/'));
        });
        $branch = new ForgeBranch('topic', new CommitId($this->topicHead), false, false, false);

        $archived = new DeleteBranchAfterArchive($this->archiver(), $forge)->delete('alpha', $branch);

        self::assertSame([$archived->ref], $seen);
    }

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/intakt-archive-'.bin2hex(random_bytes(6));
        $this->remote = new TemporaryRepository();
        $this->remote->commitFile('a.txt', 'one');
        $this->remote->checkoutNewBranch('topic');
        $this->topicHead = $this->remote->commitFile('b.txt', 'two');
        $this->remote->checkout('main');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
        $this->remote->remove();
    }

    private function head(string $branch, string $commit): MirroredHead
    {
        return new MirroredHead('alpha', new BranchHead($branch, $commit));
    }

    private function archivePath(): string
    {
        return $this->root.'/archive/alpha.git';
    }

    private function archiver(): MirrorBranchArchive
    {
        $clock = new MockClock(new DateTimeImmutable('2026-11-24 09:30:05', new DateTimeZone('UTC')));

        return new MirrorBranchArchive(new HardenedGit(), new Filesystem(), new LockFactory(new InMemoryStore()), $clock, new Mirrors($this->root.'/mirrors'), new Mirrors($this->root.'/archive'));
    }

    private function mirror(): void
    {
        $network = new NetworkGit(new RecordingClock(), new NullLogger());
        new UpdateMirror(new HardenedGit(), $network, new Filesystem(), new LockFactory(new InMemoryStore()), new Mirrors($this->root.'/mirrors'))->update('alpha', $this->remote->path);
    }

    private function git(string $directory, string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $directory, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
