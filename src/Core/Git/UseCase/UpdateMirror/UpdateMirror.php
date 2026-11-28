<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\DiskSpace;
use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Intakt\Core\Disk\Contract\NearestFolder;
use Intakt\Core\Disk\Contract\NewWorkHeld;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\GitCommand;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\NetworkGit;
use Intakt\Core\Git\Contract\NetworkGitFailed;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Contract\WorkTreeInBareRepository;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Lock\Exception\ExceptionInterface as LockFailure;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessFailure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final readonly class UpdateMirror
{
    // Because a mirror holds every ref of the remote, forced, as "clone --mirror" sets it up.
    private const string MIRROR_REFSPEC = '+refs/*:refs/*';

    private const string REMOTE_HEAD = '/^ref: (refs\/heads\/[^\t\n]+)\tHEAD$/m';

    // Both lengths, because a remote may hold SHA-1 or SHA-256 objects.
    private const string DETACHED_HEAD = '/^([0-9a-f]{40}|[0-9a-f]{64})\tHEAD$/m';

    public function __construct(
        private HardenedGit $hardened,
        private NetworkGit $network,
        private Filesystem $filesystem,
        private LockFactory $locks,
        private Mirrors $mirrors,
        private DiskSpace $disk,
    ) {
    }

    /**
     * @throws ProjectNameRefused
     * @throws NetworkGitFailed
     * @throws MirrorNotUpdated
     * @throws IOException
     * @throws RepositoryAttributesRefused
     * @throws ProcessFailure
     * @throws LockFailure
     */
    public function update(string $project, string $remote): string
    {
        $path = $this->mirrors->pathOf($project);
        $destination = $this->destinationOf($path);
        $this->holdBelowTheDiskFloors($path, $destination);

        // Asked again, because a mirror removed or published during the reading would send the write to a disk that was not measured; the next update measures again.
        if ($this->destinationOf($path) !== $destination) {
            throw MirrorNotUpdated::at($path, 'the mirror changed while its disk was measured');
        }

        return $this->writeTo($project, $remote, $destination);
    }

    // Never held below a disk floor, because it serves work already running, which a floor does not cut.
    /**
     * @throws ProjectNameRefused
     * @throws NetworkGitFailed
     * @throws MirrorNotUpdated
     * @throws IOException
     * @throws RepositoryAttributesRefused
     * @throws ProcessFailure
     * @throws LockFailure
     */
    public function refresh(string $project, string $remote): string
    {
        return $this->writeTo($project, $remote, $this->destinationOf($this->mirrors->pathOf($project)));
    }

    // Public, because the list of existing mirrors (MirrorsOnDisk) must name exactly the folders an update measures and fetches into.
    public function isMirror(string $path): bool
    {
        // Because git looks upward from a folder that is not a repository and would find the install's own checkout.
        if (!is_file($path.'/HEAD') || !is_dir($path.'/objects') || !is_dir($path.'/refs')) {
            return false;
        }

        try {
            $bare = $this->local(['rev-parse', '--is-bare-repository'], $path);
        } catch (WorkTreeInBareRepository) {
            // Because the bare call refuses a folder whose config gives it a work tree, which is no mirror either; a call that was killed or timed out is no answer and propagates, so a valid mirror is never replaced for it.
            return false;
        }

        return $bare->isSuccessful() && 'true' === trim($bare->getOutput());
    }

    private function destinationOf(string $path): string
    {
        // Because the floors guard the folder the update writes to: a fetch fills the mirror itself, which may be mounted on a disk of its own, while a first clone or the replacement of a folder that is no mirror goes to staging in the mirrors folder.
        return $this->isMirror($path) ? $path : $this->mirrors->directory;
    }

    private function holdBelowTheDiskFloors(string $path, string $destination): void
    {
        $folder = NearestFolder::of($destination);

        try {
            $hold = $this->disk->holdAt($folder);
        } catch (FreeSpaceUnreadable $unreadable) {
            throw MirrorNotUpdated::at($path, $unreadable->getMessage(), $unreadable);
        } catch (ProcessTimedOutException $timedOut) {
            // Fixed words and no cause, as for git, so a timeout names df and not the git step around it.
            throw MirrorNotUpdated::at($path, new FreeSpaceUnreadable($folder, sprintf('df timed out after %s seconds', $timedOut->getExceededTimeout()))->getMessage());
        }

        if ($hold instanceof DiskHold) {
            throw MirrorNotUpdated::at($path, $hold->reason(), new NewWorkHeld($hold));
        }
    }

    private function writeTo(string $project, string $remote, string $destination): string
    {
        $path = $this->mirrors->pathOf($project);
        $remote = $this->fromMirrorsDirectory($remote);

        // Decided by the folder that was measured, never by a new look at the path, so the write lands on the disk the floors were checked on.
        if ($destination === $path) {
            $this->fill($path, $remote);

            return $path;
        }

        $this->createMirror($project, $remote, $path);

        return $path;
    }

    private function fromMirrorsDirectory(string $remote): string
    {
        // Git reads a relative path from its working directory, which is the mirrors folder for the clone but the mirror for a fetch: resolved once, so the second update names the repository the first one cloned. A colon before any slash is a scheme or a host, which git itself never reads as a path.
        if ('' === $remote || str_starts_with($remote, '/') || 1 === preg_match('#^[^/]*:#', $remote)) {
            return $remote;
        }

        return Path::makeAbsolute($remote, $this->mirrors->directory);
    }

    private function fill(string $repository, string $remote): void
    {
        // Asked before the refs fetch, so a branch the remote's HEAD names already exists when the fetch runs and comes in with it.
        $heads = $this->network->run($this->inMirror(['ls-remote', '--symref', '--', $remote, 'HEAD'], $repository));

        // The remote is named on each call, so a mirror never depends on a remote setting; "--" ends the options, so a remote that starts with a dash is a repository, never an option. No FETCH_HEAD, because git writes the remote's address into it, and the mirror keeps no address.
        $this->network->run($this->inMirror(['fetch', '--prune', '--quiet', '--no-write-fetch-head', '--', $remote, self::MIRROR_REFSPEC], $repository));

        if (1 === preg_match(self::REMOTE_HEAD, $heads, $match)) {
            $this->mustRun(['symbolic-ref', 'HEAD', $match[1]], $repository);
            $this->mustResolveHead($repository, $match[1]);
        } elseif (1 === preg_match(self::DETACHED_HEAD, $heads, $match)) {
            // Fetched by name, because a detached HEAD may sit on a commit no ref reaches, which the refs fetch leaves out; only here, since an empty remote advertises no HEAD.
            $this->network->run($this->inMirror(['fetch', '--quiet', '--no-write-fetch-head', '--', $remote, 'HEAD'], $repository));
            // Detached, as "clone --mirror" leaves it, because a remote whose HEAD names no branch only advertises the commit.
            $this->mustRun(['update-ref', '--no-deref', 'HEAD', $match[1]], $repository);
            $this->mustResolveHead($repository, $match[1]);
        }
    }

    private function createMirror(string $project, string $remote, string $path): void
    {
        $this->filesystem->mkdir($this->mirrors->directory);
        $attempts = [];
        $staging = '';

        try {
            // A clone takes the remote's object format, which "init" would have to guess; each attempt gets a new staging folder, so a retry never clones into what a killed attempt left.
            $this->network->runEachAttempt(function () use ($project, $remote, &$attempts, &$staging): GitCall {
                $attempts[] = $staging = $this->stagingPath($project);

                // The work tree stays pinned to the mirrors folder, because a mirror clone takes none.
                return $this->withoutAttributeSource($this->hardened->call($this->mirrors->directory, 'clone', '--mirror', '--quiet', '--', $remote, $staging));
            });
            $this->forgetRemote($staging);
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
        return $this->mirrors->directory.'/.'.$project.'.git.'.bin2hex(random_bytes(6));
    }

    private function withoutAttributeSource(GitCall $call): GitCall
    {
        // Because the clone runs in the mirrors folder, where HardenedGit names SHA-1's empty tree, and a "file://" remote hands that on to its upload-pack, which refuses it in a SHA-256 repository; a bare mirror clone checks out nothing and reads no attributes, and the attributes file and system settings stay off.
        $command = array_filter([...$call->command], static fn (string $part): bool => !str_starts_with($part, '--attr-source='));

        return new GitCall(new GitCommand(...$command), $call->directory, $call->environment->unsetting('GIT_ATTR_SOURCE'));
    }

    private function forgetRemote(string $staging): void
    {
        // Because the clone saves the remote's address, credential included, as "remote.origin.url"; the remote is named on every call instead, so the mirror keeps no address.
        $this->mustRun(['config', '--remove-section', 'remote.origin'], $staging);
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
    private function local(array $arguments, string $repository): Process
    {
        $call = $this->inMirror($arguments, $repository);
        $process = new Process([...$call->command], $call->directory, [...$call->environment]);
        $process->run();

        return $process;
    }

    /**
     * @param list<string> $arguments
     */
    private function inMirror(array $arguments, string $repository): GitCall
    {
        return $this->hardened->callInBareRepository($repository, ...$arguments);
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

    private function mustResolveHead(string $repository, string $ref): void
    {
        // Because the remote may delete the branch between "ls-remote" and the fetch: the update then fails, and the next one repairs HEAD.
        if (!$this->local(['rev-parse', '--verify', '--quiet', 'HEAD'], $repository)->isSuccessful()) {
            throw MirrorNotUpdated::at($repository, sprintf('HEAD names %s, which the mirror does not hold', $ref));
        }
    }
}
