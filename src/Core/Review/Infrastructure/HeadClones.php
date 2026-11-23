<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Plugin\Forge\Contract\CommitId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

// A clone of its own for each review job, because the round's checkout is a git worktree, whose .git file the job's script refuses.
final readonly class HeadClones
{
    private const int GIT_SECONDS = 300;

    // The host's own git settings never apply, because one such as core.autocrlf would change the committed bytes the job's script compares with the head.
    private const array NO_HOST_SETTINGS = ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'];

    public function __construct(
        private Filesystem $filesystem,
        // Under the project folder like the jobs' prompts and output directories, because the engine mounts host paths.
        #[Autowire('%kernel.project_dir%/var/review-checkouts')]
        private string $folder,
    ) {
    }

    // The exact commit alone (depth 1), fetched into a fresh repository without a template, so the clone holds no other branch, no file of the host's git template, and its settings name no path on the host; in the source's object format and without the host's git settings; a random folder each, so two jobs never share one.
    /**
     * @throws ProcessFailedException
     */
    public function cloneAt(string $checkout, CommitId $head): string
    {
        $this->filesystem->mkdir($this->folder, 0o700);
        $clone = $this->folder.'/head-'.bin2hex(random_bytes(16));

        try {
            $this->git(['init', '--quiet', '--template=', '--object-format='.$this->objectFormatOf($checkout), $clone]);
            $this->git(['-C', $clone, 'fetch', '--quiet', '--no-tags', '--depth=1', $checkout, $head->hash]);
            $this->git(['-C', $clone, '-c', 'advice.detachedHead=false', 'checkout', '--quiet', '--detach', $head->hash]);
        } catch (ProcessFailedException $failed) {
            $this->remove($clone);

            throw $failed;
        }

        return $clone;
    }

    public function remove(string $clone): void
    {
        $this->filesystem->remove($clone);
    }

    // The source's own format, because a head of a SHA-256 repository cannot be fetched into a SHA-1 one; git init refuses a format it does not know.
    /**
     * @throws ProcessFailedException
     */
    private function objectFormatOf(string $checkout): string
    {
        return trim($this->git(['-C', $checkout, 'rev-parse', '--show-object-format']));
    }

    /**
     * @param list<string> $arguments
     *
     * @throws ProcessFailedException
     */
    private function git(array $arguments): string
    {
        return new Process(['git', ...$arguments], null, self::NO_HOST_SETTINGS, null, self::GIT_SECONDS)->mustRun()->getOutput();
    }
}
