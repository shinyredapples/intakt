<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\DecideDependencyChange;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Domain\ChangeNotRead;
use Intakt\Plugin\Language\Contract\DependencyFiles;
use Symfony\Component\Process\Process;

final readonly class RevisionFiles
{
    // A file and an executable file; a symbolic link, a folder or a submodule in a manifest's place is no file git can hand over as it is installed.
    private const array REGULAR_FILES = ['100644', '100755'];

    public function __construct(
        private HardenedGit $hardened,
    ) {
    }

    /**
     * @param list<string> $paths
     *
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    public function at(string $repository, string $revision, array $paths): DependencyFiles
    {
        $contents = [];
        foreach ($paths as $path) {
            $object = $this->objectOf($repository, $revision, $path);

            if (null !== $object) {
                $contents[$path] = $this->read($repository, ['cat-file', 'blob', $object]);
            }
        }

        return new DependencyFiles($contents);
    }

    /**
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    private function objectOf(string $repository, string $revision, string $path): ?string
    {
        $entry = $this->read($repository, ['ls-tree', '-z', $revision, '--', $path]);

        if ('' === $entry) {
            return null;
        }

        [$mode, , $object] = explode(' ', explode("\t", $entry, 2)[0]) + ['', '', ''];

        if (!in_array($mode, self::REGULAR_FILES, true)) {
            throw ChangeNotRead::because(sprintf('%s at %s is not a regular file', $path, $revision));
        }

        return $object;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    private function read(string $repository, array $arguments): string
    {
        $call = $this->hardened->call(['--git-dir='.$repository, ...$arguments], $repository);
        // Because a work tree beside --git-dir would make git treat the bare mirror as a checkout.
        $process = new Process($call->command, $call->directory, [...$call->environment, 'GIT_WORK_TREE' => false]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw ChangeNotRead::because(sprintf('git %s failed: %s', $arguments[0], trim($process->getErrorOutput())));
        }

        return $process->getOutput();
    }
}
