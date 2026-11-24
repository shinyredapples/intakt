<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\TakeCheckedHead;

use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\ProjectNameRefused;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

// The build job commits in a clone of its own, so the build clone holds the build only once it takes the head the hand-back check put into the mirror.
final readonly class TakeCheckedHead
{
    public function __construct(
        private HardenedGit $hardened,
        private Mirrors $mirrors,
    ) {
    }

    /**
     * @throws ProjectNameRefused
     * @throws CheckedHeadNotTaken
     * @throws RepositoryAttributesRefused
     * @throws ExceptionInterface
     */
    public function take(string $project, BranchHead $head, string $directory): void
    {
        $this->fetch($directory, $this->mirrors->pathOf($project), '+'.$head->commit.':refs/heads/'.$head->branch);
        $this->mustRun($this->hardened->call($directory, 'reset', '--hard', '--quiet'), $directory);
    }

    private function fetch(string $directory, string $mirror, string $refspec): void
    {
        // --update-head-ok, because the task's branch is the one checked out and a fetch refuses to update that one without it.
        $call = $this->hardened->call($directory, 'fetch', '--quiet', '--no-tags', '--no-write-fetch-head', '--update-head-ok', '--', $mirror, $refspec);

        // Because HardenedGit allows no transport, and git reads a local path as the "file" transport.
        $this->mustRun(new GitCall($call->command, $call->directory, $call->environment->with(GIT_ALLOW_PROTOCOL: 'file')), $directory);
    }

    private function mustRun(GitCall $call, string $directory): void
    {
        $process = new Process([...$call->command], $call->directory, [...$call->environment]);
        $process->run();

        if (!$process->isSuccessful()) {
            throw CheckedHeadNotTaken::at($directory, trim($process->getErrorOutput()));
        }
    }
}
