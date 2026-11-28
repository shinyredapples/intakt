<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Disk\Contract\DiskHold;
use Intakt\Core\Disk\Contract\DiskSpace;
use Intakt\Core\Disk\Contract\FreeSpaceUnreadable;
use Intakt\Core\Disk\Contract\NearestFolder;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Intakt\Core\Git\Domain\SourceLine;
use Intakt\Core\Git\Domain\TaskBranch;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class MakeBuildClone
{
    private const string BRANCHES = 'refs/heads/';

    private const string SOURCES = 'refs/source/';

    public function __construct(
        private HardenedGit $hardened,
        private Filesystem $filesystem,
        private Mirrors $mirrors,
        private DiskSpace $disk,
    ) {
    }

    /**
     * @throws ProjectNameRefused
     * @throws BranchNameRefused
     * @throws BuildCloneNotMade
     * @throws RepositoryAttributesRefused
     * @throws ExceptionInterface
     */
    public function make(string $project, TaskBranch $branch, string $directory): void
    {
        $mirror = $this->existingMirror($project, $directory);
        $main = $this->mainOf($mirror, $directory);
        $task = $this->taskRef($mirror, $main, $branch->name);
        $source = $this->verifiedSource($mirror, $branch->source, $directory);
        $format = $this->objectFormatOf($mirror, $directory);
        $this->holdBelowTheDiskFloors($directory);
        $this->reserve($directory);

        try {
            $this->initialize($directory, $branch->name, $format);
            $this->fetch($directory, $mirror, '+'.$main.':'.$main);

            if ($this->mirrorHolds($mirror, $task)) {
                $this->fetch($directory, $mirror, '+'.$task.':'.$task);
            } else {
                $this->startAtMain($directory, $task, $main);
            }

            $this->addSource($directory, $mirror, $source);
            $this->checkOut($directory);
            // Boundary: removes the half-made clone and rethrows, because a clone that failed half-way must never be taken for a build clone.
        } catch (Throwable $failed) {
            $this->filesystem->remove($directory);

            throw $failed;
        }
    }

    private function existingMirror(string $project, string $directory): string
    {
        $mirror = $this->mirrors->pathOf($project);

        if (!is_file($mirror.'/HEAD')) {
            throw BuildCloneNotMade::at($directory, sprintf('the project %s has no mirror yet', $project));
        }

        return $mirror;
    }

    private function mainOf(string $mirror, string $directory): string
    {
        // Main is the branch the mirror's HEAD names, because the mirror's HEAD follows the remote's default branch.
        $head = $this->run($this->inMirror($mirror, ['symbolic-ref', '--quiet', 'HEAD']));
        $main = trim($head->getOutput());

        // Because git lets HEAD name any ref under refs/, and a tag as main would put a tag in a clone that promises none.
        if (!$head->isSuccessful() || !str_starts_with($main, self::BRANCHES)) {
            throw BuildCloneNotMade::at($directory, 'the mirror\'s HEAD names no branch, so it has no main');
        }

        return $main;
    }

    private function taskRef(string $mirror, string $main, string $branch): string
    {
        $ref = $this->branchRef($mirror, $branch);

        if ($ref === $main) {
            throw BranchNameRefused::mainItself($branch);
        }

        return $ref;
    }

    private function branchRef(string $mirror, string $branch): string
    {
        // Git's own branch rule, and the answer must be the name itself, because git expands a name such as "@{-1}" to another branch.
        $checked = $this->run($this->inMirror($mirror, ['check-ref-format', '--branch', $branch]));

        if (!$checked->isSuccessful() || trim($checked->getOutput()) !== $branch) {
            throw BranchNameRefused::notAcceptedByGit($branch);
        }

        return self::BRANCHES.$branch;
    }

    private function verifiedSource(string $mirror, ?SourceLine $source, string $directory): ?string
    {
        if (!$source instanceof SourceLine) {
            return null;
        }

        $branch = $this->branchRef($mirror, $source->branch);

        if (!$this->mirrorHolds($mirror, $branch)) {
            throw BuildCloneNotMade::at($directory, sprintf('the mirror holds no branch %s to take the source from', $source->branch));
        }

        // Because a source line may only hand the build a commit its branch reaches, never one of any other branch.
        if (!$this->run($this->inMirror($mirror, ['merge-base', '--is-ancestor', $source->commit, $branch]))->isSuccessful()) {
            throw BuildCloneNotMade::at($directory, sprintf('the commit %s is not on the branch %s', $source->commit, $source->branch));
        }

        // Because merge-base peels an annotated tag to its commit, and the fetch would then put the tag object at the source ref.
        if ('commit' !== trim($this->mustRun($this->inMirror($mirror, ['cat-file', '-t', $source->commit]), $directory))) {
            throw BuildCloneNotMade::at($directory, sprintf('the object %s is not a commit', $source->commit));
        }

        // The verified commit by its id, not the branch, so the clone holds no later commit of that branch; outside refs/heads/, a commit in the clone never moves it.
        return '+'.$source->commit.':'.self::SOURCES.$source->branch;
    }

    private function objectFormatOf(string $mirror, string $directory): string
    {
        // Because a fetch from a SHA-256 mirror into a SHA-1 repository fails.
        return trim($this->mustRun($this->inMirror($mirror, ['rev-parse', '--show-object-format']), $directory));
    }

    private function holdBelowTheDiskFloors(string $directory): void
    {
        $folder = NearestFolder::of(dirname($directory));

        try {
            $hold = $this->disk->holdAt($folder);
        } catch (FreeSpaceUnreadable $unreadable) {
            throw BuildCloneNotMade::at($directory, $unreadable->getMessage(), $unreadable);
        } catch (ProcessTimedOutException $timedOut) {
            // Fixed words and no cause, as for git, so a timeout names df and not the git step around it.
            throw BuildCloneNotMade::at($directory, new FreeSpaceUnreadable($folder, sprintf('df timed out after %s seconds', $timedOut->getExceededTimeout()))->getMessage());
        }

        if ($hold instanceof DiskHold) {
            throw BuildCloneNotMade::at($directory, $hold->reason());
        }
    }

    private function reserve(string $directory): void
    {
        $this->filesystem->mkdir(dirname($directory));

        // Because a thread-safe PHP resolves the path before mkdir and follows a link, so mkdir on a dangling link makes its target; is_link does not follow it.
        if (is_link($directory) || file_exists($directory)) {
            throw BuildCloneNotMade::at($directory, 'the folder already exists');
        }

        $error = '';
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            // A single mkdir fails when anything is at the path, so of two calls for one folder only one owns it, and a folder that exists may hold someone's work.
            $made = mkdir($directory);
        } finally {
            restore_error_handler();
        }

        if (!$made) {
            throw BuildCloneNotMade::at($directory, file_exists($directory) ? 'the folder already exists' : $error);
        }
    }

    private function initialize(string $directory, string $branch, string $format): void
    {
        // The git folder is named, because git refuses the pinned work tree without one; it is the work tree's own .git, so init records no core.worktree.
        $this->mustRun($this->hardened->call($directory, '--git-dir=.git', 'init', '--quiet', '--initial-branch='.$branch, '--object-format='.$format), $directory);
    }

    private function fetch(string $directory, string $mirror, string $refspec): void
    {
        // Init and fetch instead of "clone", so the clone gets no remote, no remote-tracking refs and no tags; the task's branch is the one checked out, which a fetch only updates with --update-head-ok.
        $call = $this->hardened->call($directory, 'fetch', '--quiet', '--no-tags', '--no-write-fetch-head', '--update-head-ok', '--', $mirror, $refspec);

        // Because HardenedGit allows no transport, and git reads a local path as the "file" transport.
        $this->mustRun(new GitCall($call->command, $call->directory, $call->environment->with(GIT_ALLOW_PROTOCOL: 'file')), $directory);
    }

    private function mirrorHolds(string $mirror, string $ref): bool
    {
        return $this->run($this->inMirror($mirror, ['show-ref', '--verify', '--quiet', $ref]))->isSuccessful();
    }

    private function startAtMain(string $directory, string $task, string $main): void
    {
        $this->mustRun($this->hardened->call($directory, 'update-ref', $task, $main), $directory);
    }

    private function addSource(string $directory, string $mirror, ?string $source): void
    {
        if (null !== $source) {
            $this->fetch($directory, $mirror, $source);
        }
    }

    private function checkOut(string $directory): void
    {
        $this->mustRun($this->hardened->call($directory, 'checkout', '--force', '--quiet'), $directory);
    }

    /**
     * @param list<string> $arguments
     */
    private function inMirror(string $mirror, array $arguments): GitCall
    {
        return $this->hardened->callInBareRepository($mirror, ...$arguments);
    }

    private function mustRun(GitCall $call, string $directory): string
    {
        $process = $this->run($call);

        if (!$process->isSuccessful()) {
            throw BuildCloneNotMade::at($directory, trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    private function run(GitCall $call): Process
    {
        $process = new Process([...$call->command], $call->directory, [...$call->environment]);
        $process->run();

        return $process;
    }
}
