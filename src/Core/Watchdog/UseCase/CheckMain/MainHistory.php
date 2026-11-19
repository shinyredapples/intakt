<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

final readonly class MainHistory
{
    private const string MAIN = 'refs/heads/main';

    public function __construct(
        private HardenedGit $hardened,
    ) {
    }

    /**
     * @throws MainNotRead
     */
    public function headOf(string $repository): string
    {
        // Before any commit is listed, because a shallow repository ends its walk early and would show the missing history as clean.
        $this->mustBeComplete($repository);

        return trim($this->mustRun($repository, ['rev-parse', '--verify', '--end-of-options', self::MAIN.'^{commit}']));
    }

    // Because a commit that is no ancestor, or is not there at all, means main was rewritten.
    public function isAncestor(string $repository, string $earlier, string $head): bool
    {
        return $this->run($repository, ['merge-base', '--is-ancestor', $earlier, $head])->isSuccessful();
    }

    /**
     * @return list<string>
     *
     * @throws MainNotRead
     */
    public function firstParentCommits(string $repository, ?string $since, string $head): array
    {
        $range = null === $since ? $head : $since.'..'.$head;
        $listed = $this->mustRun($repository, ['rev-list', '--first-parent', '--reverse', $range]);

        return array_values(array_filter(explode("\n", $listed), static fn (string $line): bool => '' !== $line));
    }

    /**
     * @throws MainNotRead
     */
    public function commitText(string $repository, string $commit): string
    {
        return $this->mustRun($repository, ['cat-file', 'commit', $commit]);
    }

    /**
     * @throws MainNotRead
     */
    private function mustBeComplete(string $repository): void
    {
        if ('false' !== trim($this->mustRun($repository, ['rev-parse', '--is-shallow-repository']))) {
            throw MainNotRead::because('the repository is shallow, so its history is incomplete');
        }
    }

    /**
     * @param list<string> $arguments
     *
     * @throws MainNotRead
     */
    private function mustRun(string $repository, array $arguments): string
    {
        $process = $this->run($repository, $arguments);

        return $process->isSuccessful() ? $process->getOutput() : throw MainNotRead::because(sprintf('git %s failed', $arguments[0]));
    }

    /**
     * @param list<string> $arguments
     *
     * @throws MainNotRead
     */
    private function run(string $repository, array $arguments): Process
    {
        $directory = realpath($repository);
        if (false === $directory) {
            throw MainNotRead::because('the repository is not there');
        }

        $call = $this->hardened->call($arguments, $directory);
        $process = new Process($call->command, $call->directory, $call->environment);

        try {
            $process->run();
        } catch (ExceptionInterface) {
            throw MainNotRead::because(sprintf('git %s did not finish', $arguments[0]));
        }

        return $process;
    }
}
