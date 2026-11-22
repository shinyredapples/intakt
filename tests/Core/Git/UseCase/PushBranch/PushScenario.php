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
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Plugin\Forge\Contract\ChangeOpening;
use Intakt\Plugin\Forge\Contract\ChangeText;
use Intakt\Tests\Core\Git\TemporaryRepository;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;

// A project repository (main with one commit, the task branch with two), an empty bare remote and a throwaway signing key, because a push needs all three and each test wants its own.
final readonly class PushScenario
{
    public const string BRANCH = 'task';

    public TemporaryRepository $repository;
    public string $remote;
    public string $key;
    public RecordingLedger $ledger;
    public RecordingChangeOpening $changes;
    public string $head;
    private string $folder;

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

        $this->run(['init', '--quiet', '--bare', '--initial-branch=main', $this->remote], $this->folder);
        $this->run(['push', '--quiet', $this->remote, 'main'], $this->repository->path);
    }

    public static function generateKey(string $file, string $type = 'ed25519'): string
    {
        $process = new Process(['ssh-keygen', '-q', '-t', $type, '-N', '', '-C', 'test', '-f', $file]);
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

    public function requestOfProject(string $project, string $remote): BranchPushRequest
    {
        return new BranchPushRequest($project, $this->gitDirectory(), $remote, self::BRANCH, new AcceptedHandBack(null, $this->head, 0), new ChangeText('Add the files', 'Evidence: tests pass.'));
    }

    // The same commits with other words, because a rework can correct the pull request text alone.
    public function withText(BranchPushRequest $request, ChangeText $text): BranchPushRequest
    {
        return new BranchPushRequest($request->project, $request->repository, $request->remote, $request->branch, $request->accepted, $text);
    }

    // A second project's remote holding the same main, because two projects can share history and name their task branches alike.
    public function otherRemote(): string
    {
        $remote = $this->folder.'/other.git';
        $this->run(['init', '--quiet', '--bare', '--initial-branch=main', $remote], $this->folder);
        $this->run(['push', '--quiet', $remote, 'main'], $this->repository->path);

        return $remote;
    }

    public function push(?SigningKey $key = null): PushBranch
    {
        $git = new BranchGit(new HardenedGit());

        return new PushBranch($git, new SignedCommits($git, $key ?? $this->signingKey($this->key)), new PushRecords($this->ledger), $this->changes, new LockFactory(new InMemoryStore()));
    }

    // Through another lock store than the in-memory one, because a store can fail where memory cannot.
    public function pushWithLockStore(PersistingStoreInterface $store, ?SigningKey $key = null): PushBranch
    {
        $git = new BranchGit(new HardenedGit());

        return new PushBranch($git, new SignedCommits($git, $key ?? $this->signingKey($this->key)), new PushRecords($this->ledger), $this->changes, new LockFactory($store));
    }

    // Through another forge than the recording one, because a test of the real opener must see what it lets through.
    public function pushThrough(ChangeOpening $changes, ?Ledger $ledger = null): PushBranch
    {
        $git = new BranchGit(new HardenedGit());

        return new PushBranch($git, new SignedCommits($git, $this->signingKey($this->key)), new PushRecords($ledger ?? $this->ledger), $changes, new LockFactory(new InMemoryStore()));
    }

    public function signingKey(string $file): SigningKey
    {
        return new SigningKey($file, new Filesystem());
    }

    public function signedCommits(?string $key = null): SignedCommits
    {
        return new SignedCommits(new BranchGit(new HardenedGit()), $this->signingKey($key ?? $this->key));
    }

    public function remoteTip(?string $remote = null): ?string
    {
        $listed = $this->run(['ls-remote', $remote ?? $this->remote, 'refs/heads/'.self::BRANCH], $this->folder);

        foreach (explode("\n", $listed) as $line) {
            if (str_ends_with($line, "\trefs/heads/".self::BRANCH)) {
                return explode("\t", $line)[0];
            }
        }

        return null;
    }

    // A ref whose name ends in the task branch's own, because git's ls-remote pattern matches the tail of every ref name.
    public function pushDecoyRef(string $commit): void
    {
        $this->run(['push', '--quiet', $this->remote, $commit.':refs/heads/a/refs/heads/'.self::BRANCH], $this->repository->path);
    }

    // Git's own verdict on each pushed commit against this one key, because an outside reader sees nothing else.
    public function signatureMarks(?string $remote = null): string
    {
        $signers = $this->folder.'/allowed';
        file_put_contents($signers, 'orchestrator namespaces="git" '.implode(' ', array_slice(explode(' ', (string) file_get_contents($this->key.'.pub')), 0, 2))."\n");

        return $this->run(['-c', 'gpg.format=ssh', '-c', 'gpg.ssh.allowedSignersFile='.$signers, 'log', '--format=%G? %H', 'main..'.self::BRANCH], $remote ?? $this->remote);
    }

    // A repository as the local git forge serves it, a clone with its work tree on main, because the owner works in it.
    public function localGitForge(): string
    {
        $forge = $this->folder.'/forge';
        $this->run(['clone', '--quiet', '--single-branch', '--branch', 'main', $this->repository->path, $forge], $this->folder);

        return $forge;
    }

    // The forge's work tree on the task branch before it exists, because git refuses a push into the branch a work tree has checked out.
    public function checkOutTheBranchIn(string $forge): void
    {
        $this->run(['checkout', '--quiet', '--orphan', self::BRANCH], $forge);
    }

    // A hook that names the forge's path in its refusal, because a forge's own error output must never reach the recorded reason.
    public function refuseWithAHookIn(string $forge): void
    {
        $hook = $forge.'/.git/hooks/pre-receive';
        new Filesystem()->dumpFile($hook, "#!/bin/sh\necho \"refused by the forge at {$forge}\" >&2\nexit 1\n");
        chmod($hook, 0o755);
    }

    public function checkOutMainIn(string $forge): void
    {
        $this->run(['checkout', '--quiet', '--force', 'main'], $forge);
    }

    // Another writer's commit on the remote branch, because a second builder or a person can leave one there.
    public function pushOtherCommit(): string
    {
        $other = new TemporaryRepository();
        $other->commitFile('other.txt', "other\n");
        $this->run(['push', '--quiet', $this->remote, 'main:refs/heads/'.self::BRANCH, '--force'], $other->path);
        $tip = $other->objectAt('HEAD');
        $other->remove();

        return $tip;
    }

    // The builder's next commit on the task branch, because a rework hands back a head on top of the pushed one.
    public function commitOnTheBranch(string $path, string $content): string
    {
        $this->repository->checkout(self::BRANCH);
        $head = $this->repository->commitFile($path, $content);
        $this->repository->checkout('main');

        return $head;
    }

    public function deleteRemoteBranch(): void
    {
        $this->run(['update-ref', '-d', 'refs/heads/'.self::BRANCH], $this->remote);
    }

    public function remove(): void
    {
        $this->repository->remove();
        new Filesystem()->remove($this->folder);
    }

    /**
     * @param list<string> $arguments
     */
    private function run(array $arguments, string $directory): string
    {
        $process = new Process(['git', ...$arguments], $directory, ['GIT_DIR' => false, 'GIT_WORK_TREE' => false]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput());
        }

        return trim($process->getOutput());
    }
}
