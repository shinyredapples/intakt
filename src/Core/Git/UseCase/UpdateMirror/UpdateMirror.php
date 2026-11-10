<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\NetworkGitFailed;
use InvalidArgumentException;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Process;

final readonly class UpdateMirror
{
    private const string SAFE_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    // Because a mirror holds every ref of the remote, forced, as "clone --mirror" sets it up.
    private const string MIRROR_REFSPEC = '+refs/*:refs/*';

    private const string REMOTE_HEAD = '/^ref: (refs\/heads\/[^\t\n]+)\tHEAD$/m';

    // A SHA-1 or a SHA-256 object name.
    private const string DETACHED_HEAD = '/^([0-9a-f]{40}|[0-9a-f]{64})\tHEAD$/m';

    public function __construct(
        private HardenedGit $hardened,
        private NetworkGit $network,
        private Filesystem $filesystem,
        private LockFactory $locks,
        private string $mirrorsDirectory,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws NetworkGitFailed
     * @throws MirrorNotUpdated
     */
    public function update(string $project, string $remote): string
    {
        $path = $this->mirrorPath($project);

        if ($this->isMirror($path)) {
            $this->fill($path, $remote);

            return $path;
        }

        $this->createMirror($project, $remote, $path);

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

    private function isMirror(string $path): bool
    {
        // Because git looks upward from a folder that is not a repository and would find the install's own checkout.
        if (!is_file($path.'/HEAD') || !is_dir($path.'/objects') || !is_dir($path.'/refs')) {
            return false;
        }

        $bare = $this->local(['--git-dir='.$path, 'rev-parse', '--is-bare-repository'], $path);

        return $bare->isSuccessful() && 'true' === trim($bare->getOutput());
    }

    private function createMirror(string $project, string $remote, string $path): void
    {
        $this->filesystem->mkdir($this->mirrorsDirectory);
        $attempts = [];
        $staging = '';

        try {
            // A clone takes the remote's object format, which "init" would have to guess; each attempt gets a new staging folder, so a retry never clones into what a killed attempt left.
            $this->network->runEachAttempt(function () use ($project, $remote, &$attempts, &$staging): GitCall {
                $attempts[] = $staging = $this->stagingPath($project);

                return $this->pinned(['clone', '--mirror', '--quiet', '--', $remote, $staging], $this->mirrorsDirectory);
            });
            $published = $this->publish($project, $staging, $path);
        } finally {
            $this->filesystem->remove($attempts);
        }

        // Because the update that won fetched at its own moment, possibly before this one: the published mirror is fetched again so it is at least as fresh as this fetch.
        if (!$published) {
            $this->fill($path, $remote);
        }
    }

    private function stagingPath(string $project): string
    {
        // A leading dot is never a project name, so a staging folder cannot be taken for a mirror; a clone that dies half-way stays there and is never published.
        return $this->mirrorsDirectory.'/.'.$project.'.git.'.bin2hex(random_bytes(6));
    }

    private function fill(string $repository, string $remote): void
    {
        // The remote is named on each call, so a mirror never depends on a remote setting a half-finished clone may lack; "--" ends the options, so a remote that starts with a dash is a repository, never an option.
        $this->network->run($this->pinned(['--git-dir='.$repository, 'fetch', '--prune', '--quiet', '--', $remote, self::MIRROR_REFSPEC], $repository));

        $heads = $this->network->run($this->pinned(['--git-dir='.$repository, 'ls-remote', '--symref', '--', $remote, 'HEAD'], $repository));

        if (1 === preg_match(self::REMOTE_HEAD, $heads, $match)) {
            $this->mustRun(['--git-dir='.$repository, 'symbolic-ref', 'HEAD', $match[1]], $repository);
        } elseif (1 === preg_match(self::DETACHED_HEAD, $heads, $match)) {
            // Detached, as "clone --mirror" leaves it, because a remote whose HEAD names no branch only advertises the commit.
            $this->mustRun(['--git-dir='.$repository, 'update-ref', '--no-deref', 'HEAD', $match[1]], $repository);
        }
    }

    private function publish(string $project, string $staging, string $path): bool
    {
        $lock = $this->locks->createLock('git-mirror-'.$project);
        $lock->acquire(true);

        try {
            // Read again under the lock, because another update may have published this mirror while this one fetched; it is kept, since an in-place fetch may already be writing into it.
            if ($this->isMirror($path)) {
                return false;
            }

            $this->filesystem->remove($path);
            $this->filesystem->rename($staging, $path);

            return true;
        } catch (IOException $failed) {
            throw MirrorNotUpdated::at($path, $failed->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function mustRun(array $arguments, string $directory): void
    {
        $process = $this->local($arguments, $directory);

        if (!$process->isSuccessful()) {
            throw MirrorNotUpdated::at($directory, trim($process->getErrorOutput()));
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function local(array $arguments, string $directory): Process
    {
        $call = $this->pinned($arguments, $directory);
        $process = new Process($call->command, $call->directory, $call->environment);
        $process->run();

        return $process;
    }

    /**
     * @param list<string> $arguments
     */
    private function pinned(array $arguments, string $directory): GitCall
    {
        $call = $this->hardened->call($arguments, $directory);

        // Because a work tree beside --git-dir would make git treat the bare mirror as a checkout.
        return new GitCall($call->command, $call->directory, [...$call->environment, 'GIT_WORK_TREE' => false]);
    }
}
