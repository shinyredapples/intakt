<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Process\Process;

// Only for calls that read from a remote: a retried write could happen twice.
final readonly class NetworkGit
{
    /** Seconds to wait before the second and the third attempt. */
    public const array RETRY_DELAYS = [10, 30];

    private const int TIMEOUT_SECONDS = 600;

    // HardenedGit allows no protocol at all, so a network call names the two it needs; GIT_WORK_TREE would point a bare repository and a clone at the wrong place.
    private const array NETWORK = [
        'GIT_ALLOW_PROTOCOL' => 'file:https',
        'GIT_WORK_TREE' => false,
        'GIT_TERMINAL_PROMPT' => '0',
    ];

    public function __construct(
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws NetworkGitFailed
     */
    public function run(GitCall $call): string
    {
        $failure = '';

        foreach ([0, ...self::RETRY_DELAYS] as $attempt => $delay) {
            if ($delay > 0) {
                $this->logger->warning('A read-only git call failed, retrying.', ['attempt' => $attempt, 'delaySeconds' => $delay, 'message' => trim($failure)]);
                $this->clock->sleep($delay);
            }

            $process = new Process($call->command, $call->directory, [...$call->environment, ...self::NETWORK], timeout: self::TIMEOUT_SECONDS);
            $process->run();

            if ($process->isSuccessful()) {
                return $process->getOutput();
            }

            $failure = $process->getErrorOutput();
        }

        throw NetworkGitFailed::after(count(self::RETRY_DELAYS) + 1, $failure);
    }
}
