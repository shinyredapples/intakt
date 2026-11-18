<?php

declare(strict_types=1);

namespace Intakt\Plugin\Gate\Contract;

use InvalidArgumentException;
use Symfony\Component\Filesystem\Path;

// How a language installs its dependencies from its lock files alone, so the runner can keep one install per lock file and every check reuses it.
// The command must run no package's own code and make no file that depends on the tree, because the install is shared by every head with the same lock files.
final readonly class DependencyInstall
{
    /**
     * @param non-empty-list<string> $lockFiles
     */
    public function __construct(
        public array $lockFiles,
        public string $command,
        public string $directory,
    ) {
        foreach ([...$lockFiles, $directory] as $path) {
            if (!$this->isInsideTheProject($path)) {
                throw new InvalidArgumentException(sprintf('A dependency install names paths inside the project; "%s" is not.', $path));
            }
        }
    }

    private function isInsideTheProject(string $path): bool
    {
        return '' !== $path && !Path::isAbsolute($path) && Path::canonicalize($path) === $path && !str_starts_with($path, '..');
    }
}
