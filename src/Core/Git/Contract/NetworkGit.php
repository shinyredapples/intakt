<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

// Only for calls that read from a remote, because a retried write could happen twice.
final readonly class NetworkGit
{
    private const array RETRY_DELAYS = [10, 30];

    // HardenedGit allows no protocol at all, so a network call names the two it needs.
    private const array NETWORK = [
        'GIT_ALLOW_PROTOCOL' => 'file:https',
        'GIT_TERMINAL_PROMPT' => '0',
    ];

    public function __construct(
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private float $timeoutSeconds = 600,
    ) {
    }

    /**
     * @throws NetworkGitFailed
     */
    public function run(GitCall $call): string
    {
        return $this->runEachAttempt(static fn (): GitCall => $call);
    }

    /**
     * @param Closure(): GitCall $call
     *
     * @throws NetworkGitFailed
     */
    public function runEachAttempt(Closure $call): string
    {
        $failure = '';

        foreach ([0, ...self::RETRY_DELAYS] as $attempt => $delay) {
            if ($delay > 0) {
                $this->logger->warning('A read-only git call failed, retrying.', ['attempt' => $attempt, 'delaySeconds' => $delay, 'message' => trim($failure)]);
                $this->clock->sleep($delay);
            }

            $result = $this->attempt($call());

            if ($result instanceof Process) {
                return $result->getOutput();
            }

            $failure = $result;
        }

        throw NetworkGitFailed::after(count(self::RETRY_DELAYS) + 1, $failure);
    }

    private function attempt(GitCall $call): Process|string
    {
        $process = new Process($call->command, $call->directory, [...$call->environment, ...self::NETWORK], timeout: $this->timeoutSeconds);

        try {
            $process->run();
        } catch (RuntimeException $stopped) {
            // Because a call that hangs until the timeout or is killed by a signal is the most common transient failure, it gets the same retries as one that exits.
            return trim(($process->isStarted() ? $process->getErrorOutput() : '')."\n".$this->reason($stopped));
        }

        return $process->isSuccessful() ? $process : $process->getErrorOutput();
    }

    // Never the exception's own message, because it holds the whole command line and with it any credential in the remote's URL.
    private function reason(RuntimeException $stopped): string
    {
        return match (true) {
            $stopped instanceof ProcessTimedOutException => sprintf('git timed out after %s seconds', $this->timeoutSeconds),
            $stopped instanceof ProcessSignaledException => sprintf('git was killed by signal %d', $stopped->getSignal()),
            default => 'git could not start',
        };
    }
}
