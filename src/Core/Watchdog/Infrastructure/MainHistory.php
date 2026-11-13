<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Intakt\Core\Watchdog\Contract\WatchedMain;
use Intakt\Core\Watchdog\Domain\SeenCommit;
use Intakt\Core\Watchdog\Domain\SignatureStatus;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final readonly class MainHistory
{
    private const string BRANCHES = 'refs/heads/';

    // Because merge-base --is-ancestor answers "no" with exit 1 and a fault with another code.
    private const int NOT_AN_ANCESTOR = 1;

    public function __construct(
        private HardenedGit $hardened,
        private Filesystem $files,
    ) {
    }

    /**
     * @throws MainNotRead
     */
    public function headOf(string $repository): string
    {
        $this->mustBeComplete($repository);

        $branch = trim($this->run($repository, ['symbolic-ref', '--quiet', 'HEAD'])->getOutput());

        // Main is the branch the repository's HEAD names, because the mirror's HEAD follows the remote's default branch.
        if (!str_starts_with($branch, self::BRANCHES)) {
            throw MainNotRead::because('the repository\'s HEAD names no branch');
        }

        // Resolved once to a commit name, because the branch can move while the check runs.
        $process = $this->run($repository, ['rev-parse', '--verify', '--quiet', $branch.'^{commit}']);
        $head = trim($process->getOutput());

        if (!$process->isSuccessful() || '' === $head) {
            throw MainNotRead::because(sprintf('%s names no commit', $branch));
        }

        return $head;
    }

    /**
     * @throws MainNotRead
     */
    public function stillHolds(string $repository, string $checkedUpTo, string $head): bool
    {
        // Because a commit the repository no longer has was dropped by a rewrite.
        if (!$this->run($repository, ['cat-file', '-e', $checkedUpTo.'^{commit}'])->isSuccessful()) {
            return false;
        }

        $process = $this->run($repository, ['merge-base', '--is-ancestor', $checkedUpTo, $head]);

        if (self::NOT_AN_ANCESTOR === $process->getExitCode()) {
            return false;
        }

        $this->mustSucceed($process, 'merge-base');

        return true;
    }

    /**
     * @return list<SeenCommit>
     *
     * @throws MainNotRead
     */
    public function commitsOnMain(string $repository, string $head, WatchedMain $main): array
    {
        $signers = $this->files->tempnam(sys_get_temp_dir(), 'intakt-signers-');
        $this->files->dumpFile($signers, $main->ownerKeys->allowedSigners());

        try {
            $process = $this->run($repository, [
                '-c', 'gpg.format=ssh',
                '-c', 'gpg.ssh.program=ssh-keygen',
                '-c', 'gpg.ssh.allowedSignersFile='.$signers,
                'log', '--first-parent', '--reverse', '--format=%H %G?',
                $head, ...null === $main->checkedUpTo ? [] : ['--not', $main->checkedUpTo],
            ]);
        } finally {
            $this->files->remove($signers);
        }

        return $this->seen($this->mustSucceed($process, 'log'));
    }

    /**
     * @throws MainNotRead
     */
    private function mustBeComplete(string $repository): void
    {
        // Because a shallow history ends the first-parent walk at the cut and hides every commit below it from the first check.
        if ('false' !== trim($this->mustSucceed($this->run($repository, ['rev-parse', '--is-shallow-repository']), 'rev-parse'))) {
            throw MainNotRead::because('the repository\'s history is shallow, so commits are missing');
        }
    }

    /**
     * @return list<SeenCommit>
     */
    private function seen(string $listing): array
    {
        $commits = [];
        foreach (array_filter(explode("\n", $listing), static fn (string $line): bool => '' !== $line) as $line) {
            [$name, $letter] = explode(' ', $line, 2) + [1 => ''];
            $commits[] = new SeenCommit($name, SignatureStatus::fromGit($letter));
        }

        return $commits;
    }

    /**
     * @throws MainNotRead
     */
    private function mustSucceed(Process $process, string $command): string
    {
        if (!$process->isSuccessful()) {
            throw MainNotRead::because(sprintf('git %s failed: %s', $command, trim($process->getErrorOutput())));
        }

        return $process->getOutput();
    }

    /**
     * @param list<string> $arguments
     *
     * @throws MainNotRead
     */
    private function run(string $repository, array $arguments): Process
    {
        // Resolved first, because git would read a relative --git-dir again from inside the process directory.
        $folder = is_dir($repository) ? realpath($repository) : false;

        if (false === $folder) {
            throw MainNotRead::because(sprintf('%s is no folder', $repository));
        }

        try {
            // A -c in the arguments follows the hardened settings, so it is the one git keeps.
            $call = $this->hardened->call(['--git-dir='.$folder, ...$arguments], $folder);
        } catch (RepositoryAttributesRefused $refused) {
            throw MainNotRead::because($refused->getMessage());
        }

        // Because a work tree beside --git-dir would make git treat the bare mirror as a checkout.
        $process = new Process($call->command, $call->directory, [...$call->environment, 'GIT_WORK_TREE' => false]);
        $process->run();

        return $process;
    }
}
