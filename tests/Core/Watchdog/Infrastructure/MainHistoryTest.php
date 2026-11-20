<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\SignatureStatus;
use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use Intakt\Core\Watchdog\Infrastructure\OwnerKeyRefused;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

#[CoversClass(MainHistory::class)]
#[CoversClass(OwnerKeyRefused::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(CommitClassifier::class)]
#[UsesClass(Classification::class)]
#[UsesClass(CommitName::class)]
#[UsesClass(MainViolation::class)]
#[UsesClass(RecordedMerge::class)]
#[UsesClass(WatchedMain::class)]
final class MainHistoryTest extends TestCase
{
    private TemporaryRepository $repository;
    private string $keys;

    #[Override]
    protected function setUp(): void
    {
        $this->repository = new TemporaryRepository();
        $this->keys = sys_get_temp_dir().'/intakt-watchdog-keys-'.bin2hex(random_bytes(6));
        mkdir($this->keys);
    }

    #[Override]
    protected function tearDown(): void
    {
        $this->repository->remove();
        new Filesystem()->remove($this->keys);
    }

    public function testARecordedMergeOnMainIsClassifiedClean(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->checkoutNewBranch('change');
        $this->repository->commitEmpty('Built change');
        $this->repository->checkout('main');
        $merge = $this->repository->merge('change');

        $classification = $this->classify(new WatchedMain($seen, [new RecordedMerge($merge, $seen)]), []);

        self::assertTrue($classification->isClean());
    }

    public function testASeededUnsignedCommitOnATemporaryRepositoryIsClassifiedAsAnUnsignedViolation(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $unsigned = $this->repository->commitEmpty('Seeded unsigned commit');

        $classification = $this->classify(new WatchedMain($seen, []), [$this->publicKey('owner')]);

        self::assertEquals([new MainViolation($unsigned, Violation::Unsigned)], $classification->violations);
    }

    public function testACommitSignedByARegisteredOwnerKeyIsOwnerSigned(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        self::assertSame(SignatureStatus::OwnerSigned, $this->history([$this->publicKey('owner')])->signatureOf($signed));
    }

    public function testACommitSignedByAKeyTheOwnerDidNotRegisterIsAnUnknownKeyViolation(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $foreign = $this->repository->commitSignedWith($this->privateKey('stranger'), 'Foreign change');

        $classification = $this->classify(new WatchedMain($seen, []), [$this->publicKey('owner')]);

        self::assertEquals([new MainViolation($foreign, Violation::UnknownKey)], $classification->violations);
    }

    public function testASignedCommitIsAnUnknownKeyWhenTheOwnerRegisteredNoKey(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        self::assertSame(SignatureStatus::UnknownKey, $this->history([])->signatureOf($signed));
    }

    public function testACommitWhoseSignatureHeaderIsNotAnSshSignatureIsAnUnknownKey(): void
    {
        $this->repository->commitEmpty('Seen');
        $commit = $this->repository->commitWithASignatureHeader();

        self::assertSame(SignatureStatus::UnknownKey, $this->history([$this->publicKey('owner')])->signatureOf($commit));
    }

    public function testACommitWithASecondSignatureHeaderIsAnUnknownKeyEvenWhenTheFirstVerifies(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->rawCommit($this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change'));
        [$headers, $message] = explode("\n\n", $signed, 2);
        $signature = substr($headers, (int) strpos($headers, "\ngpgsig "));
        $commit = $this->repository->commitObject($headers.$signature."\n\n".$message);

        self::assertSame(SignatureStatus::UnknownKey, $this->history([$this->publicKey('owner')])->signatureOf($commit));
    }

    public function testASignatureHeaderInTheCommitMessageLeavesTheCommitUnsigned(): void
    {
        $this->repository->commitEmpty('Seen');
        $commit = $this->repository->commitEmpty("Subject\n\ngpgsig -----BEGIN SSH SIGNATURE-----");

        self::assertSame(SignatureStatus::Unsigned, $this->history([$this->publicKey('owner')])->signatureOf($commit));
    }

    public function testAForcePushThatDropsTheLastSeenHeadIsHistoryRewritten(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->resetTo($base);
        $this->repository->commitEmpty('Rewritten');

        $classification = $this->classify(new WatchedMain($seen, []), [$this->publicKey('owner')]);

        self::assertEquals([new MainViolation($seen, Violation::HistoryRewritten)], $classification->violations);
    }

    public function testASignedCommitInASha256RepositoryIsReadFromItsSha256HeaderAndVerifies(): void
    {
        $this->repository->remove();
        $this->repository = new TemporaryRepository('sha256');
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        self::assertSame(SignatureStatus::OwnerSigned, $this->history([$this->publicKey('owner')])->signatureOf($signed));
    }

    public function testFirstParentsSinceACommitComeOldestFirstWithoutTheCommitsAMergeBroughtFromItsSideBranch(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $first = $this->repository->commitEmpty('First');
        $this->repository->checkoutNewBranch('change');
        $this->repository->commitEmpty('Side');
        $this->repository->checkout('main');
        $merge = $this->repository->merge('change');

        self::assertSame([$first, $merge], $this->history([])->firstParentsSince($seen));
    }

    public function testMainReadsNoCommitsSinceItsOwnHead(): void
    {
        $seen = $this->repository->commitEmpty('Seen');

        self::assertSame([], $this->history([])->firstParentsSince($seen));
    }

    public function testMainIsReadFromItsBranchNotFromTheCheckedOutHead(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->checkoutNewBranch('elsewhere');
        $this->repository->commitEmpty('Not on main');

        self::assertSame([], $this->history([])->firstParentsSince($seen));
    }

    public function testACommitMainCannotFindIsAFailedReadNotARewrite(): void
    {
        $this->repository->commitEmpty('Seen');

        $this->expectException(ProcessFailedException::class);

        $this->history([])->contains(str_repeat('0', 40));
    }

    public function testARelativeRepositoryPathIsReadTheSameAsItsAbsolutePath(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $relative = rtrim(new Filesystem()->makePathRelative($this->repository->path, (string) getcwd()), '/');

        $history = new MainHistory(new HardenedGit(), $relative, [], new Filesystem());

        self::assertTrue($history->contains($seen));
    }

    public function testAnOwnerKeyWithItsCommentIsAccepted(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        self::assertSame(SignatureStatus::OwnerSigned, $this->history([$this->publicKey('owner').' owner@laptop'])->signatureOf($signed));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedKeys(): iterable
    {
        yield 'a second line' => ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA test\nowner ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIB"];
        yield 'an allowed-signers option' => ['cert-authority ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA'];
        yield 'a quote in the comment' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA "x'];
        yield 'no key bytes' => ['ssh-ed25519'];
        yield 'an empty key' => [''];
    }

    #[DataProvider('refusedKeys')]
    public function testAnOwnerKeyThatIsNotOneSshPublicKeyIsRefused(string $key): void
    {
        $this->expectException(OwnerKeyRefused::class);

        $this->history([$key]);
    }

    /**
     * @param list<string> $ownerKeys
     */
    private function classify(WatchedMain $main, array $ownerKeys): Classification
    {
        return new CommitClassifier($this->history($ownerKeys))->classify($main);
    }

    /**
     * @param list<string> $ownerKeys
     */
    private function history(array $ownerKeys): MainHistory
    {
        return new MainHistory(new HardenedGit(), $this->repository->path, $ownerKeys, new Filesystem());
    }

    private function privateKey(string $name): string
    {
        $file = $this->keys.'/'.$name;
        if (!is_file($file)) {
            new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $name, '-f', $file])->mustRun();
        }

        return $file;
    }

    private function publicKey(string $name): string
    {
        $parts = explode(' ', trim((string) file_get_contents($this->privateKey($name).'.pub')));

        return $parts[0].' '.$parts[1];
    }
}
