<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Symfony\Component\Process\Process;

// Git calls on the bare repository, never through NetworkGit, because its retries are for reads and a retried push could happen twice.
final readonly class BranchGit
{
    private const array NETWORK = ['GIT_ALLOW_PROTOCOL' => 'file:https', 'GIT_TERMINAL_PROMPT' => '0'];
    private const int TIMEOUT_SECONDS = 600;

    public function __construct(
        private HardenedGit $hardened,
    ) {
    }

    /**
     * @param list<string> $arguments
     *
     * @throws BranchNotPushed
     */
    public function output(string $repository, array $arguments, ?string $input = null): string
    {
        $process = $this->process($repository, $arguments, []);
        $process->setInput($input);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : throw BranchNotPushed::because(sprintf('git %s failed', $arguments[0] ?? ''));
    }

    /**
     * @param list<string> $arguments
     *
     * @throws BranchNotPushed
     */
    public function lines(string $repository, array $arguments): string
    {
        return trim($this->output($repository, $arguments));
    }

    /**
     * @param list<string> $arguments
     *
     * @throws BranchNotPushed
     */
    public function outputFromRemote(string $repository, array $arguments): string
    {
        $process = $this->process($repository, $arguments, self::NETWORK);
        $process->run();

        // Not the error output, because git may repeat the remote's address, and a credential can be part of it.
        return $process->isSuccessful() ? $process->getOutput() : throw BranchNotPushed::because(sprintf('git %s failed with exit code %d', $arguments[0] ?? '', $process->getExitCode() ?? -1));
    }

    // Null when the two merge cleanly, else the paths that conflict, because the caller needs both; the merge writes objects only, never a ref or a work tree.
    /**
     * @return list<string>|null
     *
     * @throws BranchNotPushed
     */
    public function mergeTree(string $repository, string $main, string $head): ?array
    {
        $process = $this->process($repository, ['merge-tree', '--write-tree', '--name-only', '--no-messages', $main, $head], []);
        $process->run();

        return match ($process->getExitCode()) {
            0 => null,
            1 => array_values(array_filter(array_slice(explode("\n", $process->getOutput()), 1), static fn (string $path): bool => '' !== $path)),
            default => throw BranchNotPushed::because('git merge-tree could not test the merge with main'),
        };
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     */
    private function process(string $repository, array $arguments, array $environment): Process
    {
        try {
            $call = $this->hardened->call(['--git-dir='.$repository, ...$arguments], $repository);
        } catch (RepositoryAttributesRefused $refused) {
            throw BranchNotPushed::because($refused->getMessage());
        }

        // Because a work tree beside --git-dir would make git treat the bare repository as a checkout.
        return new Process($call->command, $call->directory, [...$call->environment, 'GIT_WORK_TREE' => false, ...$environment], null, self::TIMEOUT_SECONDS);
    }
}
