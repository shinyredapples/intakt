<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

// Reads the commit at HEAD, never the working tree, so an uncommitted or ignored file cannot change the image of a commit.
final readonly class ProjectFiles
{
    private const int TIMEOUT_SECONDS = 120;
    // Only these, because a symlink or a submodule is no file whose content git can hand over as it is.
    private const array REGULAR_FILES = ['100644', '100755'];

    public function __construct(private HardenedGit $git = new HardenedGit())
    {
    }

    /**
     * @throws JobImageNotBuilt
     */
    public function read(string $directory, string $path): ?string
    {
        return $this->exists($directory, $path) ? $this->git($directory, ['cat-file', 'blob', 'HEAD:'.$path]) : null;
    }

    /**
     * @throws JobImageNotBuilt
     */
    public function exists(string $directory, string $path): bool
    {
        $entry = $this->git($directory, ['ls-tree', '-z', 'HEAD', '--', $path]);

        return in_array(strstr($entry, ' ', true), self::REGULAR_FILES, true);
    }

    /**
     * @throws JobImageNotBuilt
     */
    public function archive(string $directory, string $tree, string $file): void
    {
        $this->git($directory, ['archive', '--format=tar', '--output='.$file, $this->revisionOf($tree)]);
    }

    // git writes the tar itself, so a long path, a mode or a symlink of the tree stays as it is; the added file lands at the top by its name.
    /**
     * @throws JobImageNotBuilt
     */
    public function archiveAdding(string $directory, JobImagePlan $plan, string $file): void
    {
        $filesystem = new Filesystem();
        $folder = sys_get_temp_dir().'/intakt-added-'.bin2hex(random_bytes(8));
        $added = $folder.'/'.$plan->dockerfile;

        try {
            $filesystem->dumpFile($added, (string) $plan->generated);
            $this->git($directory, ['archive', '--format=tar', '--output='.$file, '--add-file='.$added, $this->revisionOf((string) $plan->tree)]);
        } finally {
            $filesystem->remove($folder);
        }
    }

    private function revisionOf(string $tree): string
    {
        return '' === $tree ? 'HEAD' : 'HEAD:'.$tree;
    }

    /**
     * @param list<string> $arguments
     */
    private function git(string $directory, array $arguments): string
    {
        // Because git finds the repository of a parent folder too, and would then hand over that project's files as this one's.
        if (!file_exists($directory.'/.git')) {
            throw JobImageNotBuilt::unreadable($directory, 'there is no .git at its top');
        }

        try {
            $call = $this->git->call($directory, ...$arguments);
            $process = new Process([...$call->command], $call->directory, [...$call->environment], timeout: self::TIMEOUT_SECONDS);
            $process->run();
        } catch (RepositoryAttributesRefused|ExceptionInterface $failure) {
            throw JobImageNotBuilt::unreadable($directory, $failure->getMessage());
        }

        return $process->isSuccessful() ? $process->getOutput() : throw JobImageNotBuilt::unreadable($directory, trim($process->getErrorOutput()));
    }
}
