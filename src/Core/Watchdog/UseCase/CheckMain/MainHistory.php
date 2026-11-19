<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\CheckMain;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Watchdog\Contract\MainNotRead;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

final readonly class MainHistory
{
    private const string MAIN = 'refs/heads/main';
    private const int ANSWERS_YES = 0;
    private const int ANSWERS_NO = 1;

    public function __construct(
        private HardenedGit $hardened,
    ) {
    }

    /**
     * @throws MainNotRead
     */
    public function headOf(string $repository): string
    {
        $this->mustBeTheRepository($repository);
        // Before any commit is listed, because a shallow repository ends its walk early and would show the missing history as clean.
        $this->mustBeComplete($repository);

        return trim($this->mustRun($repository, ['rev-parse', '--verify', '--end-of-options', self::MAIN.'^{commit}']));
    }

    // Because a commit that is no ancestor, or is not there at all, means main was rewritten.
    /**
     * @throws MainNotRead
     */
    public function isAncestor(string $repository, string $earlier, string $head): bool
    {
        return match ($this->run($repository, ['merge-base', '--is-ancestor', $earlier, $head])->getExitCode()) {
            self::ANSWERS_YES => true,
            self::ANSWERS_NO => false,
            // Because a commit that is there but cannot be read says nothing about a rewrite.
            default => $this->holds($repository, $earlier) ? throw MainNotRead::because('git merge-base could not read the history') : false,
        };
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

    // Because run() bounds git's discovery to the path itself, this call fails for anything but the top of a work tree or a git directory; --show-toplevel cannot tell, since HardenedGit pins the work tree to the path.
    /**
     * @throws MainNotRead
     */
    private function mustBeTheRepository(string $repository): void
    {
        if (!$this->run($repository, ['rev-parse', '--absolute-git-dir'])->isSuccessful()) {
            throw MainNotRead::because('the path is not the top of a work tree or a git directory');
        }
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
     * @throws MainNotRead
     */
    private function holds(string $repository, string $object): bool
    {
        return match ($this->run($repository, ['cat-file', '-e', $object])->getExitCode()) {
            self::ANSWERS_YES => true,
            self::ANSWERS_NO => false,
            default => throw MainNotRead::because('git cat-file failed'),
        };
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

        try {
            $call = $this->hardened->call($arguments, $directory);
        } catch (RepositoryAttributesRefused $refused) {
            throw MainNotRead::because($refused->getMessage());
        } catch (RuntimeException) {
            // Because HardenedGit first asks git for the repository's object format, and that call can be stopped like any other.
            throw MainNotRead::because(sprintf('git %s could not be prepared, because the look at its repository was stopped', $arguments[0]));
        }

        // The ceiling is the parent, because git would otherwise discover an enclosing repository and read its main for a plain subdirectory.
        $process = new Process($call->command, $call->directory, [...$call->environment, 'GIT_CEILING_DIRECTORIES' => dirname($directory)]);

        try {
            $process->run();
        } catch (ExceptionInterface) {
            throw MainNotRead::because(sprintf('git %s did not finish', $arguments[0]));
        }

        return $process;
    }
}
