<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\Forge\Contract\CommitId;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

// A clone of its own for each review job, because the round's checkout is a git worktree, whose .git file the job's script refuses.
final readonly class HeadClones
{
    private const int GIT_SECONDS = 300;

    public function __construct(
        private Filesystem $filesystem,
        // Under the project folder like the jobs' prompts and output directories, because the engine mounts host paths.
        #[Autowire('%kernel.project_dir%/var/review-checkouts')]
        private string $folder,
        private HardenedGit $hardened = new HardenedGit(),
    ) {
    }

    // The exact commit alone (depth 1), fetched into a fresh repository without a template, so the clone holds no other branch, no file of the host's git template, and its settings name no path on the host; in the source's object format; a random folder each, so two jobs never share one; every call hardened, because the head's .gitattributes (eol, ident, a filter) or a git setting of the host or the process (core.autocrlf) would change the committed bytes the job's script compares with the head.
    /**
     * @throws ProcessFailedException
     * @throws RepositoryAttributesRefused
     */
    public function cloneAt(string $checkout, CommitId $head): string
    {
        $format = $this->objectFormatOf($checkout);
        $this->filesystem->mkdir($this->folder, 0o700);
        $clone = $this->folder.'/head-'.bin2hex(random_bytes(16));

        // Any failure, also a timeout, removes the partial clone, so no head is left on the host.
        try {
            $this->filesystem->mkdir($clone, 0o700);
            $this->run($this->hardened->call($clone, '--git-dir=.git', 'init', '--quiet', '--template=', '--object-format='.$format));
            $fetch = $this->hardened->call($clone, 'fetch', '--quiet', '--no-tags', '--depth=1', $checkout, $head->hash);
            // Only for the fetch, because HardenedGit allows no protocol at all and the fetch reads the round's checkout by its path.
            $this->run(new GitCall($fetch->command, $fetch->directory, $fetch->environment->with(GIT_ALLOW_PROTOCOL: 'file')));
            $this->run($this->hardened->call($clone, '-c', 'core.autocrlf=false', '-c', 'advice.detachedHead=false', 'checkout', '--quiet', '--detach', $head->hash));

            // Boundary: any failure, also an unexpected one, must remove the partial clone before it ends the job.
        } catch (Throwable $failed) {
            $this->removeAfter($clone, $failed);
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
     * @throws RepositoryAttributesRefused
     */
    private function objectFormatOf(string $checkout): string
    {
        return trim($this->run($this->hardened->call($checkout, 'rev-parse', '--show-object-format')));
    }

    /**
     * @throws ProcessFailedException
     */
    private function run(GitCall $call): string
    {
        return new Process([...$call->command], $call->directory, [...$call->environment], null, self::GIT_SECONDS)->mustRun()->getOutput();
    }

    // The failure that ended the clone is rethrown even when the removal fails too, because it is the cause the job must report.
    private function removeAfter(string $clone, Throwable $failed): never
    {
        try {
            $this->remove($clone);
        } catch (IOException) {
            throw $failed;
        }

        throw $failed;
    }
}
