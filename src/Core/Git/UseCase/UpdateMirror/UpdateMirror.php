<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\NetworkGitFailed;
use InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class UpdateMirror
{
    private const string SAFE_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    public function __construct(
        private HardenedGit $hardened,
        private NetworkGit $network,
        private Filesystem $filesystem,
        private string $mirrorsDirectory,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws NetworkGitFailed
     */
    public function update(string $project, string $remote): string
    {
        $path = $this->mirrorPath($project);

        if (is_dir($path)) {
            $this->fetchMirror($path);

            return $path;
        }

        $this->cloneMirror($remote, $path);

        return $path;
    }

    private function mirrorPath(string $project): string
    {
        // Because the name becomes a path segment: a separator or a leading dot would leave the mirrors directory.
        if (1 !== preg_match(self::SAFE_NAME, $project) || str_contains($project, '..')) {
            throw new InvalidArgumentException(sprintf('"%s" is not a project name that can be a folder name', $project));
        }

        return $this->mirrorsDirectory.'/'.$project.'.git';
    }

    private function cloneMirror(string $remote, string $path): void
    {
        $this->filesystem->mkdir($this->mirrorsDirectory);
        // The "--" ends the options, so a remote that starts with a dash is a repository, never an option.
        $this->network->run($this->hardened->call(['clone', '--mirror', '--quiet', '--', $remote, $path], $this->mirrorsDirectory));
    }

    private function fetchMirror(string $path): void
    {
        $this->network->run($this->hardened->call(['fetch', '--prune', '--quiet'], $path));
    }
}
