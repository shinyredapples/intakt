<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchPushRequest;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\UseCase\PushBranch\BranchGit;
use Intakt\Core\Git\UseCase\PushBranch\PushBranch;
use Intakt\Core\Git\UseCase\PushBranch\PushRecords;
use Intakt\Core\Git\UseCase\PushBranch\SignedCommits;
use Intakt\Core\Git\UseCase\PushBranch\SigningKey;
use Intakt\Core\HandBack\Contract\AcceptedHandBack;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Tests\Core\Git\TemporaryRepository;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;

// A project repository whose main has one commit and whose task branch has two, an empty bare remote, and a throwaway signing key: everything a push meets, in temporary folders.
final class PushScenario
{
    public const string BRANCH = 'task';

    public readonly TemporaryRepository $repository;
    public readonly string $remote;
    public readonly string $key;
    public readonly RecordingLedger $ledger;
    public readonly RecordingChangeOpening $changes;
    public readonly string $head;
    private readonly string $folder;

    public function __construct()
    {
        $this->folder = sys_get_temp_dir().'/intakt-push-'.bin2hex(random_bytes(6));
        mkdir($this->folder);
        $this->remote = $this->folder.'/remote.git';
        $this->key = self::generateKey($this->folder.'/orchestrator');
        $this->ledger = new RecordingLedger();
        $this->changes = new RecordingChangeOpening();

        $this->repository = new TemporaryRepository();
        $this->repository->commitFile('a.txt', "one\n");
        $this->repository->checkoutNewBranch(self::BRANCH);
        $this->repository->commitFile('b.txt', "two\n");
        $this->head = $this->repository->commitFile('c.txt', "three\n");
        $this->repository->checkout('main');

        self::run(['init', '--quiet', '--bare', '--initial-branch=main', $this->remote], $this->folder);
        self::run(['push', '--quiet', $this->remote, 'main'], $this->repository->path);
    }

    public static function generateKey(string $file): string
    {
        $process = new Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'test', '-f', $file]);
        $process->mustRun();

        return $file;
    }

    public function gitDirectory(): string
    {
        return $this->repository->path.'/.git';
    }

    public function request(?string $base = null, ?string $head = null): BranchPushRequest
    {
        return new BranchPushRequest('widgets', $this->gitDirectory(), $this->remote, self::BRANCH, new AcceptedHandBack($base, $head ?? $this->head, 0), new ChangeText('Add the files', 'Evidence: tests pass.'));
    }

    public function push(?SigningKey $key = null): PushBranch
    {
        $git = new BranchGit(new HardenedGit());

        return new PushBranch($git, new SignedCommits($git, $key ?? $this->signingKey($this->key)), new PushRecords($this->ledger), $this->changes, new LockFactory(new InMemoryStore()));
    }

    public function signingKey(string $file): SigningKey
    {
        return new SigningKey($file, new Filesystem());
    }

    public function signedCommits(?string $key = null): SignedCommits
    {
        return new SignedCommits(new BranchGit(new HardenedGit()), $this->signingKey($key ?? $this->key));
    }

    public function remoteTip(): ?string
    {
        $listed = self::run(['ls-remote', $this->remote, 'refs/heads/'.self::BRANCH], $this->folder);

        return '' === $listed ? null : explode("\t", $listed)[0];
    }

    // What an outside reader sees: git's own verdict on each commit of the pushed branch against this one key.
    public function signatureMarks(): string
    {
        $signers = $this->folder.'/allowed';
        file_put_contents($signers, 'orchestrator namespaces="git" '.implode(' ', array_slice(explode(' ', (string) file_get_contents($this->key.'.pub')), 0, 2))."\n");

        return self::run(['-c', 'gpg.format=ssh', '-c', 'gpg.ssh.allowedSignersFile='.$signers, 'log', '--format=%G? %H', 'main..'.self::BRANCH], $this->remote);
    }

    // Another writer's commit on the remote branch, as a second builder or a person would leave it.
    public function pushOtherCommit(): string
    {
        $other = new TemporaryRepository();
        $other->commitFile('other.txt', "other\n");
        self::run(['push', '--quiet', $this->remote, 'main:refs/heads/'.self::BRANCH, '--force'], $other->path);
        $tip = $other->objectAt('HEAD');
        $other->remove();

        return $tip;
    }

    public function remove(): void
    {
        $this->repository->remove();
        new Filesystem()->remove($this->folder);
    }

    /**
     * @param list<string> $arguments
     */
    private static function run(array $arguments, string $directory): string
    {
        $process = new Process(['git', ...$arguments], $directory, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }
}
