<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\ArchiveBranch;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchNotArchived;
use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\GitCommand;
use Intakt\Core\Git\Contract\GitEnvironment;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\MirroredHead;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\NetworkGitFailed;
use Intakt\Core\Git\Contract\UnsignedCommitRefused;
use Intakt\Core\Git\Domain\CommitObject;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\UseCase\ArchiveBranch\MirrorBranchArchive;
use Intakt\Core\Git\UseCase\PushBranch\BranchGit;
use Intakt\Core\Git\UseCase\PushBranch\GuardedRun;
use Intakt\Core\Git\UseCase\PushBranch\SignedCommits;
use Intakt\Core\Git\UseCase\PushBranch\SigningKey;
use Intakt\Core\Git\UseCase\UpdateMirror\UpdateMirror;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Tests\Core\Git\RecordingClock;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Intakt\Tests\Core\Git\UseCase\PushBranch\PushScenario;
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
#[CoversClass(ArchivedBranch::class)]
#[CoversClass(MirroredHead::class)]
#[CoversClass(BranchNotArchived::class)]
#[CoversClass(UpdateMirror::class)]
#[UsesClass(BranchHead::class)]
#[UsesClass(BranchGit::class)]
#[UsesClass(GuardedRun::class)]
#[UsesClass(SignedCommits::class)]
#[UsesClass(SigningKey::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(NetworkGit::class)]
#[UsesClass(NetworkGitFailed::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(GitCommand::class)]
#[UsesClass(GitEnvironment::class)]
#[UsesClass(Mirrors::class)]
#[UsesClass(CommitObject::class)]
#[UsesClass(AcceptedHandBack::class)]
#[UsesClass(BranchNotPushed::class)]
#[UsesClass(UnsignedCommitRefused::class)]
final class MirrorBranchArchiveTest extends TestCase
{
    private const string BRANCH = 'intakt/7-add-intake';

    private TemporaryRepository $remote;
    private string $folder;
    private string $key;
    private MockClock $clock;
    private string $main;
    private string $head;

    public function testAnArchivedHeadIsKeptUnderTheArchiveNamespaceStampedWithTheMoment(): void
    {
        $archive = $this->refreshedArchive();

        $archived = $archive->archive('widgets', new BranchHead(self::BRANCH, $this->head));

        self::assertSame('refs/intakt/archive/20261124T030000Z/'.self::BRANCH, $archived->ref);
        self::assertSame($this->head, $this->mirrorGit('rev-parse', $archived->ref));
    }

    public function testAnArchiveRefSurvivesAMirrorUpdateAfterTheRemoteDeletedTheBranch(): void
    {
        $archive = $this->refreshedArchive();
        $archived = $archive->archive('widgets', new BranchHead(self::BRANCH, $this->head));
        $this->remote->deleteBranch(self::BRANCH);

        $archive->refresh('widgets', $this->remote->path);

        self::assertSame($this->head, $this->mirrorGit('rev-parse', $archived->ref));
        self::assertSame('', $this->mirrorGit('for-each-ref', 'refs/heads/'.self::BRANCH));
    }

    public function testTheNewestArchiveOfABranchIsTheOneARestoreTakes(): void
    {
        $archive = $this->refreshedArchive();
        $archive->archive('widgets', new BranchHead(self::BRANCH, $this->main));
        $this->clock->modify('+7 days');
        $newest = $archive->archive('widgets', new BranchHead(self::BRANCH, $this->head));
        $archive->archive('widgets', new BranchHead(self::BRANCH.'-other', $this->main));

        self::assertEquals($newest, $archive->latestOf('widgets', self::BRANCH));
    }

    public function testABranchNeverArchivedHasNoArchive(): void
    {
        self::assertNull($this->refreshedArchive()->latestOf('widgets', self::BRANCH));
    }

    public function testAnArchiveIsNeverOverwritten(): void
    {
        $archive = $this->refreshedArchive();
        $archive->archive('widgets', new BranchHead(self::BRANCH, $this->head));

        $this->expectException(BranchNotArchived::class);

        $archive->archive('widgets', new BranchHead(self::BRANCH, $this->main));
    }

    public function testAMirrorThatCannotBeUpdatedIsReportedAsNotArchived(): void
    {
        $this->expectException(BranchNotArchived::class);

        $this->archive()->refresh('widgets', $this->folder.'/missing');
    }

    public function testAHeadTheMirrorDoesNotHoldHasNoFacts(): void
    {
        self::assertNull($this->refreshedArchive()->headOf('widgets', new BranchHead(self::BRANCH, str_repeat('b', 40)), $this->main));
    }

    public function testAHeadIsReadWithTheTimeOfItsCommit(): void
    {
        $facts = $this->refreshedArchive()->headOf('widgets', new BranchHead(self::BRANCH, $this->head), $this->main);

        self::assertEquals(DateTimeImmutable::createFromFormat('!U', trim($this->remote->log('-1', '--format=%ct', $this->head)), new DateTimeZone('UTC')), $facts?->committedAt);
    }

    public function testABranchWithACommitThatLacksTheOrchestratorsSignatureIsClaimedByAPerson(): void
    {
        $facts = $this->refreshedArchive()->headOf('widgets', new BranchHead(self::BRANCH, $this->head), $this->main);

        self::assertTrue($facts?->claimedByPerson);
    }

    public function testABranchWhoseCommitsTheOrchestratorSignedIsNotClaimed(): void
    {
        $signed = $this->signedCommits()->of($this->remote->path.'/.git', new AcceptedHandBack(null, $this->head, 0), 'main');
        $this->remoteGit('update-ref', 'refs/heads/'.self::BRANCH, $signed);

        $facts = $this->refreshedArchive()->headOf('widgets', new BranchHead(self::BRANCH, $signed), $this->main);

        self::assertFalse($facts?->claimedByPerson);
    }

    public function testABranchWhoseSignaturesCannotBeCheckedIsClaimed(): void
    {
        $signed = $this->signedCommits()->of($this->remote->path.'/.git', new AcceptedHandBack(null, $this->head, 0), 'main');
        $this->remoteGit('update-ref', 'refs/heads/'.self::BRANCH, $signed);
        $this->key = $this->folder.'/missing-key';

        $facts = $this->refreshedArchive()->headOf('widgets', new BranchHead(self::BRANCH, $signed), $this->main);

        self::assertTrue($facts?->claimedByPerson);
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-archive-'.bin2hex(random_bytes(6));
        mkdir($this->folder);
        $this->key = PushScenario::generateKey($this->folder.'/orchestrator');
        $this->clock = new MockClock('2026-11-24 03:00:00 UTC');
        $this->remote = new TemporaryRepository();
        $this->main = $this->remote->commitFile('a.txt', "one\n");
        $this->remote->checkoutNewBranch(self::BRANCH);
        $this->head = $this->remote->commitFile('b.txt', "two\n");
        $this->remote->checkout('main');
    }

    protected function tearDown(): void
    {
        $this->remote->remove();
        new Filesystem()->remove($this->folder);
    }

    private function refreshedArchive(): MirrorBranchArchive
    {
        $archive = $this->archive();
        $archive->refresh('widgets', $this->remote->path);

        return $archive;
    }

    private function archive(): MirrorBranchArchive
    {
        $mirrors = new Mirrors($this->folder.'/mirrors');
        $update = new UpdateMirror(new HardenedGit(), new NetworkGit(new RecordingClock(), new NullLogger()), new Filesystem(), new LockFactory(new InMemoryStore()), $mirrors);

        return new MirrorBranchArchive($update, $mirrors, new BranchGit(new HardenedGit(), new GuardedRun()), $this->signedCommits(), $this->clock);
    }

    private function signedCommits(): SignedCommits
    {
        return new SignedCommits(new BranchGit(new HardenedGit(), new GuardedRun()), new SigningKey($this->key, new Filesystem(), new GuardedRun()));
    }

    private function mirrorGit(string ...$arguments): string
    {
        return $this->outputOf(['git', '--git-dir='.$this->folder.'/mirrors/widgets.git', ...array_values($arguments)]);
    }

    private function remoteGit(string ...$arguments): string
    {
        return $this->outputOf(['git', '-C', $this->remote->path, ...array_values($arguments)]);
    }

    /**
     * @param list<string> $command
     */
    private function outputOf(array $command): string
    {
        $process = new Process($command);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
