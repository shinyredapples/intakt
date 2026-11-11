<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Domain\Mirrors;
use InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class MakeBuildClone
{
    private const string BRANCHES = 'refs/heads/';

    public function __construct(
        private HardenedGit $hardened,
        private Filesystem $filesystem,
        private Mirrors $mirrors,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws BuildCloneNotMade
     * @throws RepositoryAttributesRefused
     */
    public function make(string $project, string $branch, string $directory): void
    {
        $mirror = $this->existingMirror($project, $directory);
        $main = $this->mainOf($mirror, $directory);
        $task = $this->taskRef($mirror, $main, $branch);
        $this->refuseExistingFolder($directory);

        try {
            $this->initialize($directory, $mirror, $branch);
            $this->fetch($directory, $mirror, $main);

            if ($this->mirrorHolds($mirror, $task)) {
                $this->fetch($directory, $mirror, $task);
            } else {
                $this->startAtMain($directory, $task, $main);
            }

            $this->checkOut($directory);
        } catch (Throwable $failed) {
            // Because a clone that failed half-way must never be taken for a build clone.
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

        if (!$head->isSuccessful()) {
            throw BuildCloneNotMade::at($directory, 'the mirror\'s HEAD names no branch, so it has no main');
        }

        return trim($head->getOutput());
    }

    private function taskRef(string $mirror, string $main, string $branch): string
    {
        // Git's own branch rule, and the answer must be the name itself, because git expands a name such as "@{-1}" to another branch.
        $checked = $this->run($this->inMirror($mirror, ['check-ref-format', '--branch', $branch]));

        if (!$checked->isSuccessful() || trim($checked->getOutput()) !== $branch) {
            throw new InvalidArgumentException(sprintf('"%s" is not a branch name git accepts', $branch));
        }

        if (self::BRANCHES.$branch === $main) {
            throw new InvalidArgumentException(sprintf('the task\'s branch "%s" is main itself', $branch));
        }

        return self::BRANCHES.$branch;
    }

    private function refuseExistingFolder(string $directory): void
    {
        // Because the clone must hold nothing but what it fetched, and a folder that exists may hold someone's work.
        if (file_exists($directory) || is_link($directory)) {
            throw BuildCloneNotMade::at($directory, 'the folder already exists');
        }
    }

    private function initialize(string $directory, string $mirror, string $branch): void
    {
        // The mirror's object format, because a fetch from a SHA-256 mirror into a SHA-1 repository fails.
        $format = trim($this->mustRun($this->inMirror($mirror, ['rev-parse', '--show-object-format']), $directory));
        $this->filesystem->mkdir($directory);
        $init = $this->hardened->call(['init', '--quiet', '--initial-branch='.$branch, '--object-format='.$format], $directory);

        // Because git refuses a work tree without a git folder, which init has yet to make; it makes both in the folder it runs in.
        $this->mustRun(new GitCall($init->command, $init->directory, [...$init->environment, 'GIT_WORK_TREE' => false]), $directory);
    }

    private function fetch(string $directory, string $mirror, string $ref): void
    {
        // Init and fetch instead of "clone", so the clone gets no remote, no remote-tracking refs and no tags; the task's branch is the one checked out, which a fetch only updates with --update-head-ok.
        $call = $this->hardened->call(['fetch', '--quiet', '--no-tags', '--no-write-fetch-head', '--update-head-ok', '--', $mirror, '+'.$ref.':'.$ref], $directory);

        // Because HardenedGit allows no transport, and git reads a local path as the "file" transport.
        $this->mustRun(new GitCall($call->command, $call->directory, [...$call->environment, 'GIT_ALLOW_PROTOCOL' => 'file']), $directory);
    }

    private function mirrorHolds(string $mirror, string $ref): bool
    {
        return $this->run($this->inMirror($mirror, ['show-ref', '--verify', '--quiet', $ref]))->isSuccessful();
    }

    private function startAtMain(string $directory, string $task, string $main): void
    {
        $this->mustRun($this->hardened->call(['update-ref', $task, $main], $directory), $directory);
    }

    private function checkOut(string $directory): void
    {
        $this->mustRun($this->hardened->call(['checkout', '--force', '--quiet'], $directory), $directory);
    }

    /**
     * @param list<string> $arguments
     */
    private function inMirror(string $mirror, array $arguments): GitCall
    {
        $call = $this->hardened->call(['--git-dir='.$mirror, ...$arguments], $mirror);

        // Because a work tree beside --git-dir would make git treat the bare mirror as a checkout.
        return new GitCall($call->command, $call->directory, [...$call->environment, 'GIT_WORK_TREE' => false]);
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
        $process = new Process($call->command, $call->directory, $call->environment);
        $process->run();

        return $process;
    }
}
