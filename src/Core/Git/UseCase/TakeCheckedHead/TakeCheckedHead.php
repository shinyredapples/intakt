<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\TakeCheckedHead;

use Intakt\Core\Git\Contract\GitCall;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Domain\Mirrors;
use Intakt\Core\Git\Domain\TaskBranch;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

// The build job commits in a clone of its own, so the build clone holds the build only once it takes the head the hand-back check put into the mirror.
final readonly class TakeCheckedHead
{
    private const string OBJECT_ID = '/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D';

    public function __construct(
        private HardenedGit $hardened,
        private Mirrors $mirrors,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     * @throws CheckedHeadNotTaken
     * @throws RepositoryAttributesRefused
     * @throws ExceptionInterface
     */
    public function take(string $project, TaskBranch $branch, string $head, string $directory): void
    {
        // Because a refspec takes a ref name or an option as readily as an object id.
        if (1 !== preg_match(self::OBJECT_ID, $head)) {
            throw new InvalidArgumentException(sprintf('"%s" is no object id', $head));
        }

        $this->fetch($directory, $this->mirrors->pathOf($project), '+'.$head.':refs/heads/'.$branch->name);
        $this->mustRun($this->hardened->call(['reset', '--hard', '--quiet'], $directory), $directory);
    }

    private function fetch(string $directory, string $mirror, string $refspec): void
    {
        // The task's branch is the one checked out, which a fetch only updates with --update-head-ok.
        $call = $this->hardened->call(['fetch', '--quiet', '--no-tags', '--no-write-fetch-head', '--update-head-ok', '--', $mirror, $refspec], $directory);

        // Because HardenedGit allows no transport, and git reads a local path as the "file" transport.
        $this->mustRun(new GitCall($call->command, $call->directory, [...$call->environment, 'GIT_ALLOW_PROTOCOL' => 'file']), $directory);
    }

    private function mustRun(GitCall $call, string $directory): void
    {
        $process = new Process($call->command, $call->directory, $call->environment);
        $process->run();

        if (!$process->isSuccessful()) {
            throw CheckedHeadNotTaken::at($directory, trim($process->getErrorOutput()));
        }
    }
}
