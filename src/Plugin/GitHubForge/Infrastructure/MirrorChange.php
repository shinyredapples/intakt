<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\Forge\Contract\BinaryFiles;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Domain\CommitNotRead;
use Intakt\Plugin\GitHubForge\Domain\HeldChange;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

// The head as one commit and everything it adds to main, read from git objects, so the owner approves exactly the commit and the text that would be pushed.
final readonly class MirrorChange
{
    private const string BRANCHES = 'refs/heads/';

    public function __construct(
        private HardenedGit $hardened,
        private BinaryFiles $binaryFiles,
    ) {
    }

    /**
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     * @throws ForgeReadFailed
     */
    public function of(string $repository, string $head): HeldChange
    {
        $repository = $this->absolute($repository);
        $commit = $this->commit($repository, $head);

        return new HeldChange($commit, $this->diff($repository, $this->mergeBase($repository, $commit), $commit));
    }

    /**
     * @throws CommitNotRead
     */
    private function absolute(string $repository): string
    {
        // Because git runs inside the repository, where a relative --git-dir would resolve a second time.
        $directory = getcwd();
        if (false === $directory) {
            throw CommitNotRead::because('the current directory is unknown, so the repository path does not resolve');
        }

        return Path::makeAbsolute($repository, $directory);
    }

    /**
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     */
    private function commit(string $repository, string $name): string
    {
        $process = $this->run($repository, ['rev-parse', '--verify', '--quiet', '--end-of-options', $name.'^{commit}']);
        $commit = trim($process->getOutput());

        if (!$process->isSuccessful() || '' === $commit) {
            throw CommitNotRead::because(sprintf('%s names no commit of the repository', $name));
        }

        return $commit;
    }

    /**
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     */
    private function mergeBase(string $repository, string $commit): string
    {
        $branch = $this->run($repository, ['symbolic-ref', '--quiet', 'HEAD']);
        $main = trim($branch->getOutput());
        if (!$branch->isSuccessful() || !str_starts_with($main, self::BRANCHES)) {
            throw CommitNotRead::because('the repository\'s HEAD names no branch, so main is unknown');
        }

        $process = $this->run($repository, ['merge-base', '--all', $main, $commit]);
        $bases = array_values(array_filter(explode("\n", $process->getOutput()), static fn (string $base): bool => '' !== $base));

        // One base only, because with several the change against main has no single diff to show.
        return $process->isSuccessful() && 1 === count($bases)
            ? $bases[0]
            : throw CommitNotRead::because(sprintf('the head and main have %d merge bases, so the change has no single diff', count($bases)));
    }

    /**
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     * @throws ForgeReadFailed
     */
    private function diff(string $repository, string $base, string $commit): string
    {
        // As text, because git's guess from the first bytes would hide a file as "Binary files differ", while BinaryFiles names only a plain image whose blobs hold a NUL byte; full ids, because the approval pins an image's bytes by them and a short one could name another object later.
        $process = $this->run($repository, ['diff-tree', '-r', '-p', '--text', '--no-renames', '--no-color', '--no-ext-diff', '--no-textconv', '--full-index', '--ignore-submodules=none', $base, $commit]);

        if (!$process->isSuccessful()) {
            throw CommitNotRead::because(sprintf('git diff-tree failed: %s', trim($process->getErrorOutput())));
        }

        return $this->binaryFiles->withImagesNamed($process->getOutput(), fn (string $id): string => $this->blob($repository, $id));
    }

    /**
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     */
    private function blob(string $repository, string $id): string
    {
        $process = $this->run($repository, ['cat-file', 'blob', $id]);

        return $process->isSuccessful()
            ? $process->getOutput()
            : throw CommitNotRead::because(sprintf('the blob %s could not be read: %s', $id, trim($process->getErrorOutput())));
    }

    /**
     * @param list<string> $arguments
     *
     * @throws RepositoryAttributesRefused
     */
    private function run(string $repository, array $arguments): Process
    {
        $call = $this->hardened->callInBareRepository($repository, ...$arguments);
        $process = new Process([...$call->command], $call->directory, [...$call->environment]);
        $process->run();

        return $process;
    }
}
