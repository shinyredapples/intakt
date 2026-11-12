<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckHandBack;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\HandBack\Contract\HandBackNotChecked;
use Symfony\Component\Process\Process;

final readonly class RepositoryGit
{
    public function __construct(
        private HardenedGit $hardened,
    ) {
    }

    /**
     * @param list<string> $arguments
     */
    public function succeeds(string $repository, array $arguments): bool
    {
        return $this->run($repository, $arguments, null)->isSuccessful();
    }

    /**
     * @param list<string> $arguments
     */
    public function answer(string $repository, array $arguments): ?string
    {
        $process = $this->run($repository, $arguments, null);

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * @param list<string> $arguments
     *
     * @throws HandBackNotChecked
     */
    public function output(string $repository, array $arguments): string
    {
        return trim($this->mustRun($repository, $arguments, null));
    }

    /**
     * @param list<string> $arguments
     *
     * @throws HandBackNotChecked
     */
    public function exactOutput(string $repository, array $arguments): string
    {
        return $this->mustRun($repository, $arguments, null);
    }

    /**
     * @param list<string> $arguments
     *
     * @throws HandBackNotChecked
     */
    public function outputWithInput(string $repository, array $arguments, string $input): string
    {
        return trim($this->mustRun($repository, $arguments, $input));
    }

    /**
     * @param list<string> $arguments
     *
     * @throws HandBackNotChecked
     */
    private function mustRun(string $repository, array $arguments, ?string $input): string
    {
        $process = $this->run($repository, $arguments, $input);

        if (!$process->isSuccessful()) {
            throw HandBackNotChecked::in($repository, sprintf('git %s failed', $arguments[0] ?? ''));
        }

        return $process->getOutput();
    }

    /**
     * @param list<string> $arguments
     */
    private function run(string $repository, array $arguments, ?string $input): Process
    {
        $call = $this->hardened->call(['--git-dir='.$repository, ...$arguments], $repository);

        // Because a work tree beside --git-dir would make git treat the bare repository as a checkout.
        $process = new Process($call->command, $call->directory, [...$call->environment, 'GIT_WORK_TREE' => false], $input);
        $process->run();

        return $process;
    }
}
