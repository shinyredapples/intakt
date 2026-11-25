<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use SensitiveParameter;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

// The one way the push runs git and ssh-keygen, because a process that hits its limit, is killed or cannot start throws instead of exiting, and that has to end as BranchNotPushed like every other failure of the push.
final readonly class GuardedRun
{
    /**
     * @throws BranchNotPushed
     */
    public function run(#[SensitiveParameter] Process $process, string $program): Process
    {
        try {
            $process->run();
        } catch (RuntimeException $stopped) {
            // Fixed words, and never the caught exception as the cause, because its message holds the whole command line, and with it a credential in the remote's address or the signing key's file.
            $reason = match (true) {
                $stopped instanceof ProcessTimedOutException => sprintf('%s timed out after %s seconds', $program, $process->getTimeout()),
                $stopped instanceof ProcessSignaledException => sprintf('%s was killed by signal %d', $program, $stopped->getSignal()),
                default => sprintf('%s could not start', $program),
            };

            throw BranchNotPushed::because($reason);
        }

        return $process;
    }
}
