<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\Forge\Contract\CommitId;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

// A clone of its own for each review job, because the round's checkout is a git worktree, whose .git file the job's script refuses.
final readonly class HeadClones
{
    private const int GIT_SECONDS = 300;

    // HardenedGit allows no protocol at all, and the fetch reads the round's checkout by its path.
    private const array LOCAL_FETCH = ['GIT_ALLOW_PROTOCOL' => 'file'];

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
     * @throws RuntimeException
     */
    public function cloneAt(string $checkout, CommitId $head): string
    {
        $format = $this->objectFormatOf($checkout);
        $this->filesystem->mkdir($this->folder, 0o700);
        $clone = $this->folder.'/head-'.bin2hex(random_bytes(16));

        // Any failure, also a timeout, removes the partial clone, so no head is left on the host.
        try {
            $this->filesystem->mkdir($clone, 0o700);
            $this->run($this->hardened->call(['--git-dir=.git', 'init', '--quiet', '--template=', '--object-format='.$format], $clone));
            $this->run($this->hardened->call(['fetch', '--quiet', '--no-tags', '--depth=1', $checkout, $head->hash], $clone), self::LOCAL_FETCH);
            $this->run($this->hardened->call(['-c', 'core.autocrlf=false', '-c', 'advice.detachedHead=false', 'checkout', '--quiet', '--detach', $head->hash], $clone));
        } catch (RuntimeException $failed) {
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
     * @throws RepositoryAttributesRefused
     * @throws RuntimeException
     */
    private function objectFormatOf(string $checkout): string
    {
        return trim($this->run($this->hardened->call(['rev-parse', '--show-object-format'], $checkout)));
    }

    /**
     * @param array<string, string> $environment
     *
     * @throws ProcessFailedException
     * @throws RuntimeException
     */
    private function run(GitCall $call, array $environment = []): string
    {
        return new Process($call->command, $call->directory, [...$call->environment, ...$environment], null, self::GIT_SECONDS)->mustRun()->getOutput();
    }
}
