<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
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
    ) {
    }

    /**
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
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
     */
    private function diff(string $repository, string $base, string $commit): string
    {
        // As text, because a binary file shown as "Binary files differ" would be approved unseen; full ids, because a short one could name another object later.
        $process = $this->run($repository, ['diff-tree', '-r', '-p', '--text', '--no-renames', '--no-color', '--no-ext-diff', '--no-textconv', '--full-index', '--ignore-submodules=none', $base, $commit]);

        return $process->isSuccessful()
            ? $process->getOutput()
            : throw CommitNotRead::because(sprintf('git diff-tree failed: %s', trim($process->getErrorOutput())));
    }

    /**
     * @param list<string> $arguments
     *
     * @throws RepositoryAttributesRefused
     */
    private function run(string $repository, array $arguments): Process
    {
        $call = $this->hardened->call(['--git-dir='.$repository, ...$arguments], $repository);

        // Because a work tree beside --git-dir would make git treat the bare mirror as a checkout.
        $process = new Process($call->command, $call->directory, [...$call->environment, 'GIT_WORK_TREE' => false]);
        $process->run();

        return $process;
    }
}
