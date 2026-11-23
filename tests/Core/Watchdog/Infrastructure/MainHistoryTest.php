<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watchdog\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Watchdog\Contract\Classification;
use Intakt\Core\Watchdog\Contract\CommitHistory;
use Intakt\Core\Watchdog\Contract\CommitName;
use Intakt\Core\Watchdog\Contract\MainHistoryRewritten;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\MainViolation;
use Intakt\Core\Watchdog\Contract\RecordedMerge;
use Intakt\Core\Watchdog\Contract\SignatureStatus;
use Intakt\Core\Watchdog\Contract\Violation;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\CommitClassifier;
use Intakt\Core\Watchdog\Infrastructure\FirstParentChain;
use Intakt\Core\Watchdog\Infrastructure\MainHistory;
use Intakt\Core\Watchdog\Infrastructure\OwnerKey;
use Intakt\Core\Watchdog\Infrastructure\OwnerKeyRefused;
use Intakt\Core\Watchdog\Infrastructure\TrustedSignatures;
use Intakt\Tests\Core\Git\TemporaryRepository;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[CoversClass(MainHistory::class)]
#[CoversClass(FirstParentChain::class)]
#[CoversClass(OwnerKey::class)]
#[CoversClass(OwnerKeyRefused::class)]
#[CoversClass(TrustedSignatures::class)]
#[CoversClass(MainHistoryRewritten::class)]
#[CoversClass(MainNotRead::class)]
#[UsesClass(HardenedGit::class)]
#[UsesClass(RepositoryAttributesRefused::class)]
#[UsesClass(GitCall::class)]
#[UsesClass(CommitClassifier::class)]
#[UsesClass(Classification::class)]
#[UsesClass(CommitName::class)]
#[UsesClass(MainViolation::class)]
#[UsesClass(RecordedMerge::class)]
#[UsesClass(WatchedMain::class)]
final class MainHistoryTest extends TestCase
{
    // The base point of P-256, because a security-key fixture must carry a point that lies on its curve.
    private const string P256_GENERATOR = "\x04\x6b\x17\xd1\xf2\xe1\x2c\x42\x47\xf8\xbc\xe6\xe5\x63\xa4\x40\xf2\x77\x03\x7d\x81\x2d\xeb\x33\xa0\xf4\xa1\x39\x45\xd8\x98\xc2\x96"
        ."\x4f\xe3\x42\xe2\xfe\x1a\x7f\x9b\x8e\xe7\xeb\x4a\x7c\x0f\x9e\x16\x2b\xce\x33\x57\x6b\x31\x5e\xce\xcb\xb6\x40\x68\x37\xbf\x51\xf5";

    private TemporaryRepository $repository;
    private string $keys;

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

    public function testACommitSignedByAGivenPeerKeyIsPeerSigned(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('peer'), 'Peer merge');

        self::assertSame(SignatureStatus::PeerSigned, $this->historyWithPeers([$this->publicKey('owner')], [$this->publicKey('peer')])->signatureOf($signed));
    }

    public function testACommitSignedByAPeerKeyIsPeerSignedWithoutAnyOwnerKey(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('peer'), 'Peer merge');

        self::assertSame(SignatureStatus::PeerSigned, $this->historyWithPeers([], [$this->publicKey('peer')])->signatureOf($signed));
    }

    public function testACommitSignedByTheOwnerKeyStaysOwnerSignedWhenPeerKeysAreGiven(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        self::assertSame(SignatureStatus::OwnerSigned, $this->historyWithPeers([$this->publicKey('owner')], [$this->publicKey('peer')])->signatureOf($signed));
    }

    public function testACommitSignedByAnOwnerKeyIsNeverPeerSignedWhenOnlyPeerKeysAreGiven(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        self::assertSame(SignatureStatus::UnknownKey, $this->historyWithPeers([], [$this->publicKey('peer')])->signatureOf($signed));
    }

    public function testACommitSignedByAPeerKeyIsNeverOwnerSignedWhenOnlyOwnerKeysAreGiven(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('peer'), 'Peer merge');

        self::assertSame(SignatureStatus::UnknownKey, $this->historyWithPeers([$this->publicKey('owner')], [])->signatureOf($signed));
    }

    public function testACommitSignedByAKeyNeitherOwnerNorPeerGaveIsAnUnknownKey(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('stranger'), 'Foreign change');

        self::assertSame(SignatureStatus::UnknownKey, $this->historyWithPeers([$this->publicKey('owner')], [$this->publicKey('peer')])->signatureOf($signed));
    }

    public function testAKeyGivenAsOwnerAndAsPeerReadsAsOwnerSigned(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        self::assertSame(SignatureStatus::OwnerSigned, $this->historyWithPeers([$this->publicKey('owner')], [$this->publicKey('owner')])->signatureOf($signed));
    }

    public function testAKeyGivenAsOwnerAndAsPeerIsAnUnknownKeyWhenTheOwnerCheckIsInterrupted(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $history = $this->historyWithPeers([$this->publicKey('owner')], [$this->publicKey('owner')]);
        $path = $this->interruptOwnerChecks();

        try {
            self::assertSame(SignatureStatus::UnknownKey, $history->signatureOf($signed));
        } finally {
            putenv('PATH='.$path);
        }
    }

    public function testAPeerKeyStaysPeerSignedBehindTheInterruptingProgramWhenNoOwnerKeyIsAskedFor(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('peer'), 'Peer merge');
        $history = $this->historyWithPeers([], [$this->publicKey('peer')]);
        $path = $this->interruptOwnerChecks();

        try {
            self::assertSame(SignatureStatus::PeerSigned, $history->signatureOf($signed));
        } finally {
            putenv('PATH='.$path);
        }
    }

    #[DataProvider('exitCodesThatAreNoAnswer')]
    public function testAKeyGivenAsOwnerAndAsPeerIsAnUnknownKeyWhenTheOwnerCheckEndsWithAnExitCodeThatIsNoAnswer(int $exitCode): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $history = $this->historyWithPeers([$this->publicKey('owner')], [$this->publicKey('owner')]);
        $path = $this->endOwnerChecksWith('exit '.$exitCode);

        try {
            self::assertSame(SignatureStatus::UnknownKey, $history->signatureOf($signed));
        } finally {
            putenv('PATH='.$path);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function exitCodesThatAreNoAnswer(): iterable
    {
        yield 'a general error' => [1];
        yield 'a program without the execute bit' => [126];
        yield 'a program that is missing' => [127];
        yield 'the code right below a refused signature' => [254];
    }

    public function testAKeyGivenAsOwnerAndAsPeerIsAnUnknownKeyWhenTheOwnerCheckCannotLaunchItsProgram(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $history = $this->historyWithPeers([$this->publicKey('owner')], [$this->publicKey('owner')]);
        $path = $this->endOwnerChecksWith('exec "$0.missing"');

        try {
            self::assertSame(SignatureStatus::UnknownKey, $history->signatureOf($signed));
        } finally {
            putenv('PATH='.$path);
        }
    }

    public function testAPeerKeyStaysPeerSignedBehindTheProgramThatDoesNotLaunchWhenNoOwnerKeyIsAskedFor(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('peer'), 'Peer merge');
        $history = $this->historyWithPeers([], [$this->publicKey('peer')]);
        $path = $this->endOwnerChecksWith('exit 127');

        try {
            self::assertSame(SignatureStatus::PeerSigned, $history->signatureOf($signed));
        } finally {
            putenv('PATH='.$path);
        }
    }

    public function testASignatureTheRealProgramRefusesForTheOwnerExitsWith255AndStillReachesThePeerCheck(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('peer'), 'Peer merge');
        $history = $this->historyWithPeers([$this->publicKey('owner')], [$this->publicKey('peer')]);
        $exits = $this->keys.'/owner-exits';
        $path = $this->endOwnerChecksWith('"$program" "$@"; status=$?; echo $status >> '.escapeshellarg($exits).'; exit $status');

        try {
            self::assertSame(SignatureStatus::PeerSigned, $history->signatureOf($signed));
            self::assertSame("255\n", file_get_contents($exits));
        } finally {
            putenv('PATH='.$path);
        }
    }

    public function testAMalformedPeerKeyIsRefusedNamingItAsAPeerKeyAndItsPosition(): void
    {
        try {
            $this->historyWithPeers([$this->publicKey('owner')], [$this->publicKey('peer'), 'cert-authority ssh-ed25519 A']);
            self::fail('A malformed peer key must be refused.');
        } catch (OwnerKeyRefused $refused) {
            self::assertStringStartsWith('Peer key 2 is not one SSH public key', $refused->getMessage());
        }
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
        [$headers, $message] = explode("\n\n", $this->repository->rawCommit($this->repository->commitEmpty('Owner change')), 2);
        // The owner signs a payload that already holds the second header, so a parser that strips only the first header rebuilds exactly the signed bytes.
        $second = "\ngpgsig -----BEGIN SSH SIGNATURE-----";
        $payload = $this->keys.'/payload';
        file_put_contents($payload, $headers.$second."\n\n".$message);
        new Process(['ssh-keygen', '-q', '-Y', 'sign', '-n', 'git', '-f', $this->privateKey('owner'), $payload])->mustRun();
        $signature = str_replace("\n", "\n ", rtrim((string) file_get_contents($payload.'.sig')));
        $commit = $this->repository->commitObject($headers."\ngpgsig ".$signature.$second."\n\n".$message);

        self::assertSame(SignatureStatus::UnknownKey, $this->history([$this->publicKey('owner')])->signatureOf($commit));
    }

    public function testACommitCarryingTheOtherFormatsSignatureHeaderIsAnUnknownKeyEvenWhenItsOwnSignatureVerifies(): void
    {
        $this->repository->commitEmpty('Seen');
        [$headers, $message] = explode("\n\n", $this->repository->rawCommit($this->repository->commitEmpty('Owner change')), 2);
        $headers .= "\ngpgsig-sha256 -----BEGIN SSH SIGNATURE-----";
        $payload = $this->keys.'/payload';
        file_put_contents($payload, $headers."\n\n".$message);
        new Process(['ssh-keygen', '-q', '-Y', 'sign', '-n', 'git', '-f', $this->privateKey('owner'), $payload])->mustRun();
        $signature = str_replace("\n", "\n ", rtrim((string) file_get_contents($payload.'.sig')));
        $commit = $this->repository->commitObject($headers."\ngpgsig ".$signature."\n\n".$message);

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

    public function testTheWalkFromACommitMainDroppedThrowsMainHistoryRewritten(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->resetTo($base);

        $this->expectException(MainHistoryRewritten::class);

        $this->history([])->firstParentsSince($seen);
    }

    public function testAForcePushBetweenTheAncestryCheckAndTheWalkIsHistoryRewrittenOnTheLastSeenHead(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $seen = $this->repository->commitEmpty('Seen');
        $forcePushAfterTheAncestryCheck = new readonly class($this->history([]), $this->repository, $base) implements CommitHistory {
            public function __construct(private MainHistory $history, private TemporaryRepository $repository, private string $base)
            {
            }

            #[Override]
            public function contains(string $commit): bool
            {
                $contains = $this->history->contains($commit);
                $this->repository->resetTo($this->base);

                return $contains;
            }

            #[Override]
            public function firstParentsSince(string $commit): array
            {
                return $this->history->firstParentsSince($commit);
            }

            #[Override]
            public function signatureOf(string $commit): SignatureStatus
            {
                return $this->history->signatureOf($commit);
            }

            #[Override]
            public function broughtInBy(RecordedMerge $merge): array
            {
                return $this->history->broughtInBy($merge);
            }
        };

        $classification = new CommitClassifier($forcePushAfterTheAncestryCheck)->classify(new WatchedMain($seen, []));

        self::assertEquals([new MainViolation($seen, Violation::HistoryRewritten)], $classification->violations);
    }

    public function testAMissingMainBranchIsAFailedReadEvenWhenATagCarriesItsFullName(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->tag('refs/heads/main');
        $this->repository->checkoutNewBranch('other');
        $this->repository->deleteBranch('main');

        $this->expectException(ProcessFailedException::class);

        $this->history([])->contains($seen);
    }

    public function testDeletingTheMainBranchLeavesTheRepositoryAnInheritedGitDirNamesAlone(): void
    {
        $inherited = new TemporaryRepository();
        foreach ([$this->repository, $inherited] as $repository) {
            $repository->commitEmpty('Seen');
            $repository->checkoutNewBranch('other');
        }
        $priorProcess = getenv('GIT_DIR');
        $priorServer = $_SERVER['GIT_DIR'] ?? null;
        $_SERVER['GIT_DIR'] = $inherited->path.'/.git';
        putenv('GIT_DIR='.$_SERVER['GIT_DIR']);

        try {
            $this->repository->deleteBranch('main');
        } finally {
            $this->restoreGitDir($priorProcess, $priorServer);
            $mainOfTheInherited = is_file($inherited->path.'/.git/refs/heads/main');
            $mainOfTheTest = is_file($this->repository->path.'/.git/refs/heads/main');
            $inherited->remove();
        }

        self::assertSame([true, false], [$mainOfTheInherited, $mainOfTheTest]);
    }

    public function testTheSignersFileIsRemovedWhenTheSignatureFileCannotBeAllocated(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $filesystem = new class extends Filesystem {
            /** @var list<string> */
            public array $allocated = [];

            #[Override]
            public function tempnam(string $dir, string $prefix, string $suffix = ''): string
            {
                if ([] !== $this->allocated) {
                    throw new IOException('No second temporary file.');
                }

                return $this->allocated[] = parent::tempnam($dir, $prefix, $suffix);
            }
        };

        $status = new MainHistory(new HardenedGit(), $this->repository->path, [$this->publicKey('owner')], $filesystem)->signatureOf($signed);

        self::assertSame(SignatureStatus::UnknownKey, $status);
        self::assertFileDoesNotExist($filesystem->allocated[0]);
    }

    public function testAnOwnerSignedCommitIsAnUnknownKeyWhenTheVerificationFilesCannotBeWritten(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        $filesystem = new class extends Filesystem {
            #[Override]
            public function dumpFile(string $filename, $content): void
            {
                throw new IOException('No space for the verification files.');
            }
        };

        self::assertSame(SignatureStatus::UnknownKey, new MainHistory(new HardenedGit(), $this->repository->path, [$this->publicKey('owner')], $filesystem)->signatureOf($signed));
    }

    public function testASignedCommitInASha256RepositoryIsReadFromItsSha256HeaderAndVerifies(): void
    {
        $this->repository->remove();
        $this->repository = new TemporaryRepository('sha256');
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        self::assertSame(SignatureStatus::OwnerSigned, $this->history([$this->publicKey('owner')])->signatureOf($signed));
    }

    public function testASha1RepositoryNamesCommitsWithFortyCharacters(): void
    {
        $this->repository->commitEmpty('Seen');

        self::assertSame(40, $this->history([])->commitNameLength());
    }

    public function testASha256RepositoryNamesCommitsWithSixtyFourCharacters(): void
    {
        $this->repository->remove();
        $this->repository = new TemporaryRepository('sha256');
        $this->repository->commitEmpty('Seen');

        self::assertSame(64, $this->history([])->commitNameLength());
    }

    public function testTheNameLengthIsNotReadFromAFolderThatIsNotTheRepository(): void
    {
        $this->expectException(MainNotRead::class);

        new MainHistory(new HardenedGit(), $this->repository->path.'/missing', [], new Filesystem())->commitNameLength();
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

    public function testAShallowRepositoryIsRefusedBeforeAnyCommitIsWalked(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->commitEmpty('New');
        $this->repository->cutHistoryBelow($seen);

        $this->expectException(MainNotRead::class);
        $this->expectExceptionMessageMatches('/ is a shallow repository\.$/');

        $this->history([])->firstParentsSince($base);
    }

    public function testASubdirectoryOfATemporaryRepositoryRaisesMainNotRead(): void
    {
        $seen = $this->repository->commitFile('sub/file', 'content');

        $this->expectException(MainNotRead::class);
        $this->expectExceptionMessageMatches('/ is not the repository itself /');

        new MainHistory(new HardenedGit(), $this->repository->path.'/sub', [], new Filesystem())->contains($seen);
    }

    public function testTheGitDirectoryOfARepositoryWithAWorkTreeRaisesMainNotRead(): void
    {
        $seen = $this->repository->commitEmpty('Seen');

        $this->expectException(MainNotRead::class);
        $this->expectExceptionMessageMatches('/ is not the repository itself /');

        new MainHistory(new HardenedGit(), $this->repository->path.'/.git', [], new Filesystem())->contains($seen);
    }

    public function testABareRepositoryIsReadThroughItsGitDirectory(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $bare = $this->keys.'/bare.git';
        new Process(['git', 'clone', '--quiet', '--bare', $this->repository->path, $bare])->mustRun();

        self::assertTrue(new MainHistory(new HardenedGit(), $bare, [], new Filesystem())->contains($seen));
    }

    public function testAMissingRepositoryPathRaisesMainNotRead(): void
    {
        $seen = $this->repository->commitEmpty('Seen');

        $this->expectException(MainNotRead::class);
        $this->expectExceptionMessageMatches('/\/missing is not an existing folder\.$/');

        new MainHistory(new HardenedGit(), $this->repository->path.'/missing', [], new Filesystem())->contains($seen);
    }

    public function testASubdirectoryNamedByTheCoreWorktreeSettingRaisesMainNotRead(): void
    {
        $seen = $this->repository->commitFile('sub/file', 'content');
        new Process(['git', '-C', $this->repository->path, 'config', 'core.worktree', $this->repository->path.'/sub'])->mustRun();

        $this->expectException(MainNotRead::class);
        $this->expectExceptionMessageMatches('/ is not the repository itself /');

        new MainHistory(new HardenedGit(), $this->repository->path.'/sub', [], new Filesystem())->contains($seen);
    }

    public function testARepositoryFolderNameEndingInASpaceIsReadAsTheRepositoryItself(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $spaced = $this->repository->path.' ';
        rename($this->repository->path, $spaced);

        try {
            self::assertTrue(new MainHistory(new HardenedGit(), $spaced, [], new Filesystem())->contains($seen));
        } finally {
            rename($spaced, $this->repository->path);
        }
    }

    public function testAFileAsTheRepositoryPathRaisesMainNotRead(): void
    {
        $seen = $this->repository->commitFile('child/file', 'content');

        $this->expectException(MainNotRead::class);
        $this->expectExceptionMessageMatches('/\/child\/file is not an existing folder\.$/');

        new MainHistory(new HardenedGit(), $this->repository->path.'/child/file', [], new Filesystem())->contains($seen);
    }

    public function testDamagedShallowMetadataRaisesMainNotRead(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        file_put_contents($this->repository->path.'/.git/shallow', "invalid-shallow-entry\n");

        $this->expectException(MainNotRead::class);

        $this->history([])->contains($seen);
    }

    public function testARepositoryCutShallowAfterTheFirstCheckIsRefusedNotReadAsARewrite(): void
    {
        $base = $this->repository->commitEmpty('Base');
        $cut = $this->repository->commitEmpty('Cut');
        $this->repository->commitEmpty('Tip');
        $path = $this->cutShallowAfterGitAnswers('--is-shallow-repository', $cut);

        try {
            $this->expectException(MainNotRead::class);

            $this->history([])->firstParentsSince($base);
        } finally {
            putenv('PATH='.$path);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commitsTheLateCutLands(): iterable
    {
        yield 'the tip, so the walk lists it alone' => ['Tip'];
        yield 'a commit in between, so the walk starts there' => ['Unsigned'];
    }

    #[DataProvider('commitsTheLateCutLands')]
    public function testARepositoryCutShallowAfterTheAncestryCheckIsRefusedInsteadOfWalkedWithCommitsMissing(string $cutAt): void
    {
        $base = $this->repository->commitEmpty('Base');
        $commits = ['Unsigned' => $this->repository->commitEmpty('Unsigned'), 'Tip' => $this->repository->commitEmpty('Tip')];
        $path = $this->cutShallowAfterGitAnswers('--is-ancestor', $commits[$cutAt]);

        try {
            $this->expectException(MainNotRead::class);
            $this->expectExceptionMessageMatches('/ is not an unbroken chain/');

            $this->history([])->firstParentsSince($base);
        } finally {
            putenv('PATH='.$path);
        }
    }

    public function testTheWalkFromACommitMainHoldsOnlyThroughASecondParentIsReadDownToTheFork(): void
    {
        $this->repository->commitEmpty('Fork');
        $this->repository->checkoutNewBranch('change');
        $side = $this->repository->commitEmpty('Side');
        $this->repository->checkout('main');
        $seen = $this->repository->commitEmpty('Seen');
        $this->repository->checkout('change');
        $merge = $this->repository->merge('main');
        $this->repository->checkout('main');
        $this->repository->resetTo($merge);

        self::assertSame([$side, $merge], $this->history([])->firstParentsSince($seen));
    }

    public function testACommitMainAlreadyHeldThroughASecondParentIsNotReadAsNewWhenTheRepositoryIsCutShallowDuringTheWalk(): void
    {
        $this->repository->commitEmpty('Fork');
        $this->repository->checkoutNewBranch('side');
        $this->repository->commitEmpty('Already reachable');
        $this->repository->checkoutNewBranch('first-parent');
        $this->repository->commitEmpty('New');
        $this->repository->checkout('side');
        $cut = $this->repository->commitEmpty('Side tip');
        $this->repository->checkout('main');
        $this->repository->commitEmpty('Main side');
        $seen = $this->repository->merge('side');
        $this->repository->checkout('first-parent');
        $tip = $this->repository->merge('main');
        $this->repository->checkout('main');
        $this->repository->resetTo($tip);
        $path = $this->cutShallowWhileGitRuns('rev-list', $cut);

        try {
            $this->expectException(MainNotRead::class);
            $this->expectExceptionMessageMatches('/ is not an unbroken chain/');

            $this->history([])->firstParentsSince($seen);
        } finally {
            putenv('PATH='.$path);
        }
    }

    public function testALinkedWorkTreeIsReadWhenThePathOfItsGitDirectoryHoldsALineBreak(): void
    {
        $seen = $this->repository->commitEmpty('Seen');
        $broken = $this->repository->path."\nproject";
        rename($this->repository->path, $broken);

        try {
            new Process(['git', '-C', $broken, 'worktree', 'add', '--quiet', '--detach', $broken.'/linked'])->mustRun();

            self::assertTrue(new MainHistory(new HardenedGit(), $broken.'/linked', [], new Filesystem())->contains($seen));
        } finally {
            rename($broken, $this->repository->path);
        }
    }

    public function testAFolderWhoseGitFileNamesNoGitDirectoryRaisesMainNotRead(): void
    {
        $seen = $this->repository->commitFile('sub/file', 'content');
        $this->repository->configure('core.worktree', $this->repository->path.'/sub');
        file_put_contents($this->repository->path.'/sub/.git', "gitdir: ../missing\n");

        $this->expectException(MainNotRead::class);
        $this->expectExceptionMessageMatches('/ is not the repository itself /');

        new MainHistory(new HardenedGit(), $this->repository->path.'/sub', [], new Filesystem())->contains($seen);
    }

    public function testAnExistingInfoAttributesFileRaisesMainNotRead(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');
        file_put_contents($this->repository->path.'/.git/info/attributes', "* filter=anything\n");

        $this->expectException(MainNotRead::class);
        $this->expectExceptionMessageMatches('/^Main was not read, because .*\/\.git\/info\/attributes exists: /');

        $this->history([$this->publicKey('owner')])->signatureOf($signed);
    }

    public function testAnOwnerKeyWithItsCommentIsAccepted(): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner'), 'Owner change');

        self::assertSame(SignatureStatus::OwnerSigned, $this->history([$this->publicKey('owner').' owner@laptop'])->signatureOf($signed));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function keyTypes(): iterable
    {
        yield 'ed25519' => [['-t', 'ed25519']];
        yield 'rsa' => [['-t', 'rsa']];
        yield 'ecdsa nistp256' => [['-t', 'ecdsa', '-b', '256']];
        yield 'ecdsa nistp384' => [['-t', 'ecdsa', '-b', '384']];
        yield 'ecdsa nistp521' => [['-t', 'ecdsa', '-b', '521']];
    }

    /**
     * @param list<string> $type
     */
    #[DataProvider('keyTypes')]
    public function testAnOwnerKeyOfEachKeyTypeSshKeygenWritesIsAcceptedAndVerifies(array $type): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('owner', $type), 'Owner change');

        self::assertSame(SignatureStatus::OwnerSigned, $this->history([$this->publicKey('owner')])->signatureOf($signed));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function securityKeys(): iterable
    {
        yield 'ed25519' => ['ssh-ed25519', [str_repeat("\x01", 32), 'ssh:']];
        yield 'ecdsa' => ['ecdsa-sha2-nistp256', ['nistp256', self::P256_GENERATOR, 'ssh:']];
    }

    /**
     * @param list<string> $fields
     */
    #[DataProvider('securityKeys')]
    public function testAnOwnerKeyOfASecurityKeyTypeIsAccepted(string $plainType, array $fields): void
    {
        $type = 'sk-'.$plainType.'@openssh.com';
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('stranger'), 'Foreign change');

        self::assertSame(SignatureStatus::UnknownKey, $this->history([self::key($type, [$type, ...$fields])])->signatureOf($signed));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysAtTheShapeLimits(): iterable
    {
        yield 'an rsa modulus of exactly 1024 bits after a leading zero byte' => [self::key('ssh-rsa', ['ssh-rsa', "\x01\x00\x01", "\x00\x80".str_repeat("\x01", 127)])];
        yield 'an uncompressed ecdsa point on its curve' => [self::key('ecdsa-sha2-nistp256', ['ecdsa-sha2-nistp256', 'nistp256', self::P256_GENERATOR])];
    }

    #[DataProvider('keysAtTheShapeLimits')]
    public function testAnOwnerKeyAtTheShapeLimitsIsAcceptedAndFailsClosedForAForeignSignature(string $key): void
    {
        $this->repository->commitEmpty('Seen');
        $signed = $this->repository->commitSignedWith($this->privateKey('stranger'), 'Foreign change');

        self::assertSame(SignatureStatus::UnknownKey, $this->history([$key])->signatureOf($signed));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedKeys(): iterable
    {
        $type = 'ssh-ed25519';
        yield 'one key byte where the key declares 32' => [$type.' '.base64_encode(pack('N', strlen($type)).$type.pack('N', 32)."\x01")];
        yield 'an ed25519 key of 31 bytes' => [self::key($type, [$type, str_repeat("\x01", 31)])];
        yield 'an ed25519 key of 33 bytes' => [self::key($type, [$type, str_repeat("\x01", 33)])];
        yield 'bytes after the key' => [$type.' '.base64_encode(pack('N', strlen($type)).$type.pack('N', 32).str_repeat("\x01", 33))];
        yield 'a second field after the key' => [self::key($type, [$type, str_repeat("\x01", 32), 'more'])];
        yield 'a length cut off after two bytes' => [$type.' '.base64_encode(pack('N', strlen($type)).$type."\x00\x00")];
        yield 'an rsa key without its modulus' => [self::key('ssh-rsa', ['ssh-rsa', "\x01\x00\x01"])];
        yield 'an rsa key with an empty modulus' => [self::key('ssh-rsa', ['ssh-rsa', "\x01\x00\x01", ''])];
        yield 'an ecdsa key that names another curve' => [self::key('ecdsa-sha2-nistp256', ['ecdsa-sha2-nistp256', 'nistp384', "\x04".str_repeat("\x01", 64)])];
        yield 'an ecdsa point one byte short' => [self::key('ecdsa-sha2-nistp256', ['ecdsa-sha2-nistp256', 'nistp256', "\x04".str_repeat("\x01", 63)])];
        yield 'a security key without its application' => [self::key('sk-ssh-ed25519@openssh.com', ['sk-ssh-ed25519@openssh.com', str_repeat("\x01", 32)])];
        $securityKeyType = sprintf('sk-%s@openssh.com', 'ecdsa-sha2-nistp384');
        yield 'a security key of a curve without a security key type' => [self::key($securityKeyType, [$securityKeyType, 'nistp384', "\x04".str_repeat("\x01", 96), 'ssh:'])];
        yield 'an ecdsa point off its curve' => [self::key('ecdsa-sha2-nistp256', ['ecdsa-sha2-nistp256', 'nistp256', "\x04".str_repeat("\x00", 64)])];
        $securityKeyType = sprintf('sk-%s@openssh.com', 'ecdsa-sha2-nistp256');
        yield 'a security key point off its curve' => [self::key($securityKeyType, [$securityKeyType, 'nistp256', "\x04".str_repeat("\x01", 64), 'ssh:'])];
        yield 'an rsa modulus of zero' => [self::key('ssh-rsa', ['ssh-rsa', "\x01\x00\x01", "\x00"])];
        yield 'an rsa modulus encoded as a negative mpint' => [self::key('ssh-rsa', ['ssh-rsa', "\x01\x00\x01", "\x80".str_repeat("\x01", 127)])];
        yield 'an rsa exponent encoded as a negative mpint' => [self::key('ssh-rsa', ['ssh-rsa', "\x80\x01", "\x00\x80".str_repeat("\x01", 127)])];
        yield 'an rsa exponent of zero' => [self::key('ssh-rsa', ['ssh-rsa', "\x00\x00", "\x00\x80".str_repeat("\x01", 127)])];
        yield 'an rsa modulus of 1023 bits' => [self::key('ssh-rsa', ['ssh-rsa', "\x01\x00\x01", "\x00\x7f".str_repeat("\xff", 127)])];
        yield 'an ecdsa point in hybrid form' => [self::key('ecdsa-sha2-nistp256', ['ecdsa-sha2-nistp256', 'nistp256', "\x07".substr(self::P256_GENERATOR, 1)])];
        yield 'a key type the check does not know' => [self::key('ssh-unknown', ['ssh-unknown', str_repeat("\x01", 32)])];
        yield 'a second line' => ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA test\nowner ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIB"];
        yield 'an allowed-signers option' => ['cert-authority ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA'];
        yield 'a quote in the comment' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA "x'];
        yield 'no key bytes' => ['ssh-ed25519'];
        yield 'an empty key' => [''];
        yield 'key bytes that are not Base64' => ['ssh-ed25519 A'];
        yield 'key bytes of another type' => ['ssh-rsa AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl'];
        yield 'a type with no key after it' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5'];
    }

    #[DataProvider('refusedKeys')]
    public function testAnOwnerKeyThatIsNotOneSshPublicKeyIsRefused(string $key): void
    {
        $this->expectException(OwnerKeyRefused::class);

        $this->history([$key]);
    }

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

    // A git in front of the real one writes the shallow entry right after git answered the named query, because only that forces the cut between two reads; the search path to restore is returned.
    private function cutShallowAfterGitAnswers(string $option, string $entry): string
    {
        $bin = $this->keys.'/shallow-bin';
        mkdir($bin);
        $git = escapeshellarg((string) new ExecutableFinder()->find('git'));
        $shallow = escapeshellarg($this->repository->path.'/.git/shallow');
        $script = "#!/bin/sh\ncase \" \$* \" in\n    *\" ".$option." \"*)\n        ".$git." \"\$@\" || exit \$?\n        printf '%s\\n' ".escapeshellarg($entry).' > '.$shallow."\n        ;;\n    *) exec ".$git." \"\$@\" ;;\nesac\n";
        file_put_contents($bin.'/git', $script);
        chmod($bin.'/git', 0o700);
        $path = (string) getenv('PATH');
        putenv('PATH='.$bin.':'.$path);

        return $path;
    }

    // A git in front of the real one holds the shallow entry only while the named command runs, because a cut that is gone again before and after it is one no check around the walk can see; the search path to restore is returned.
    private function cutShallowWhileGitRuns(string $command, string $entry): string
    {
        $bin = $this->keys.'/shallow-bin';
        mkdir($bin);
        $git = escapeshellarg((string) new ExecutableFinder()->find('git'));
        $shallow = escapeshellarg($this->repository->path.'/.git/shallow');
        $script = "#!/bin/sh\ncase \" \$* \" in\n    *\" ".$command." \"*)\n        printf '%s\\n' ".escapeshellarg($entry).' > '.$shallow."\n        ".$git." \"\$@\"\n        status=\$?\n        rm -f ".$shallow."\n        exit \$status\n        ;;\n    *) exec ".$git." \"\$@\" ;;\nesac\n";
        file_put_contents($bin.'/git', $script);
        chmod($bin.'/git', 0o700);
        $path = (string) getenv('PATH');
        putenv('PATH='.$bin.':'.$path);

        return $path;
    }

    // An ssh-keygen in front of the real one kills itself when asked for the owner principal, because only a signal forces an owner check that ends without an answer; the search path to restore is returned.
    private function interruptOwnerChecks(): string
    {
        return $this->endOwnerChecksWith('kill -TERM $$');
    }

    // An ssh-keygen in front of the real one runs the given shell line when asked for the owner principal and the real program ($program) otherwise, because a launch failure is an exit code and only a program in front forces it on the owner check alone; the search path to restore is returned.
    private function endOwnerChecksWith(string $shellLine): string
    {
        $bin = $this->keys.'/owner-check-bin';
        mkdir($bin);
        $sshKeygen = escapeshellarg((string) new ExecutableFinder()->find('ssh-keygen'));
        $script = "#!/bin/sh\nprogram=".$sshKeygen."\ncase \" \$* \" in\n    *\" -I owner \"*) ".$shellLine." ;;\nesac\nexec \"\$program\" \"\$@\"\n";
        file_put_contents($bin.'/ssh-keygen', $script);
        chmod($bin.'/ssh-keygen', 0o700);
        $path = (string) getenv('PATH');
        putenv('PATH='.$bin.':'.$path);

        return $path;
    }

    // The values from before the test, because later tests must inherit the environment the run started with.
    private function restoreGitDir(string|false $process, mixed $server): void
    {
        putenv(false === $process ? 'GIT_DIR' : 'GIT_DIR='.$process);
        if (null === $server) {
            unset($_SERVER['GIT_DIR']);
        } else {
            $_SERVER['GIT_DIR'] = $server;
        }
    }

    /**
     * @param list<string> $ownerKeys
     */
    private function classify(WatchedMain $main, array $ownerKeys): Classification
    {
        return new CommitClassifier($this->history($ownerKeys))->classify($main);
    }

    /**
     * @param list<string> $fields
     */
    private static function key(string $type, array $fields): string
    {
        return $type.' '.base64_encode(implode('', array_map(static fn (string $field): string => pack('N', strlen($field)).$field, $fields)));
    }

    /**
     * @param list<string> $type
     */
    private function privateKey(string $name, array $type = ['-t', 'ed25519']): string
    {
        $file = $this->keys.'/'.$name;
        if (!is_file($file)) {
            new Process(['ssh-keygen', '-q', ...$type, '-N', '', '-C', $name, '-f', $file])->mustRun();
        }

        return $file;
    }

    /**
     * @param list<string> $ownerKeys
     */
    private function history(array $ownerKeys): MainHistory
    {
        return new MainHistory(new HardenedGit(), $this->repository->path, $ownerKeys, new Filesystem());
    }

    /**
     * @param list<string> $ownerKeys
     * @param list<string> $peerKeys
     */
    private function historyWithPeers(array $ownerKeys, array $peerKeys): MainHistory
    {
        return new MainHistory(new HardenedGit(), $this->repository->path, $ownerKeys, new Filesystem(), $peerKeys);
    }

    private function publicKey(string $name): string
    {
        $parts = explode(' ', trim((string) file_get_contents($this->privateKey($name).'.pub')));

        return $parts[0].' '.$parts[1];
    }
}
