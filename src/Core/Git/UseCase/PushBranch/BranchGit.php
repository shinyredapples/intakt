<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\PushBranch;

use Intakt\Core\Git\Contract\BranchNotPushed;
use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

// Git calls on the bare repository, never through NetworkGit, because its retries are for reads and a retried push could happen twice.
final readonly class BranchGit
{
    private const array NETWORK = ['GIT_ALLOW_PROTOCOL' => 'file:https', 'GIT_TERMINAL_PROMPT' => '0'];

    public function __construct(
        private HardenedGit $hardened,
        private GuardedRun $guardedRun,
        private float $timeoutSeconds = 600,
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

        return $this->finished($process, $arguments)->isSuccessful() ? $process->getOutput() : throw BranchNotPushed::because(sprintf('git %s failed', $arguments[0] ?? ''));
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
        $process = $this->finished($this->process($repository, $arguments, self::NETWORK), $arguments);

        // Not the error output, because git may repeat the remote's address, and a credential can be part of it.
        return $process->isSuccessful() ? $process->getOutput() : throw BranchNotPushed::because(sprintf('git %s failed with exit code %d', $arguments[0] ?? '', $process->getExitCode() ?? -1));
    }

    // The exit code alone, because the caller records a refused push as its own outcome, and git's error output may repeat the remote's address.
    /**
     * @param list<string> $arguments
     *
     * @throws BranchNotPushed
     */
    public function exitCodeAtRemote(string $repository, array $arguments): int
    {
        return $this->finished($this->process($repository, $arguments, self::NETWORK), $arguments)->getExitCode() ?? -1;
    }

    // The config entries that change where git sends a remote string (url.<base>.insteadOf and url.<base>.pushInsteadOf rewrites, and every remote.<name>.* key, includes followed) as key and value pairs, in one call, because git applies them before it reads or pushes, so the address the caller checked would not be the one git reaches.
    /**
     * @return list<array{string, string}>
     *
     * @throws BranchNotPushed
     */
    public function addressEntries(string $repository): array
    {
        $arguments = ['config', '--null', '--get-regexp', '^(url\..*\.(push)?insteadof|remote\..*)$'];
        $process = $this->finished($this->process($repository, $arguments, []), $arguments);

        // Exit code 1 means no such entry, because git config answers a search that matches no key with it.
        return match ($process->getExitCode()) {
            0 => array_map(static function (string $entry): array {
                $parts = explode("\n", $entry, 2);

                return [$parts[0], $parts[1] ?? ''];
            }, array_values(array_filter(explode("\0", $process->getOutput()), static fn (string $entry): bool => '' !== $entry))),
            1 => [],
            default => throw BranchNotPushed::because('git config could not list the address rewrites and remote sections of the repository'),
        };
    }

    // Null when the two merge cleanly, else the paths that conflict, because the caller needs both; the merge writes objects only, never a ref or a work tree.
    /**
     * @return list<string>|null
     *
     * @throws BranchNotPushed
     */
    public function mergeTree(string $repository, string $main, string $head): ?array
    {
        // Ended by NUL (-z), because git otherwise prints a path that holds a byte outside ASCII, a quote or a line break as a quoted string, and the builder needs the file's name.
        $arguments = ['merge-tree', '--write-tree', '--name-only', '--no-messages', '-z', $main, $head];
        $process = $this->finished($this->process($repository, $arguments, []), $arguments);

        return match ($process->getExitCode()) {
            0 => null,
            1 => array_values(array_filter(array_slice(explode("\0", $process->getOutput()), 1), static fn (string $path): bool => '' !== $path)),
            default => throw BranchNotPushed::because('git merge-tree could not test the merge with main'),
        };
    }

    /**
     * @param list<string>          $arguments
     * @param array<string, string> $environment
     *
     * @throws BranchNotPushed
     */
    private function process(string $repository, array $arguments, array $environment): Process
    {
        try {
            $call = $this->hardened->callInBareRepository($arguments, $repository);
        } catch (RepositoryAttributesRefused $refused) {
            throw BranchNotPushed::because($refused->getMessage());
        } catch (RuntimeException) {
            // Because HardenedGit first asks git for the repository's object format, and that call can be killed, hit its limit like any other, or find no bare repository; its exception is not chained, as in GuardedRun.
            throw BranchNotPushed::because(sprintf('git %s could not be prepared, because the look at its repository was stopped or found no bare repository', $arguments[0] ?? ''));
        }

        return new Process($call->command, $call->directory, [...$call->environment, ...$environment], null, $this->timeoutSeconds);
    }

    // Every call goes through this one step, so none leaves the push as a Process exception.
    /**
     * @param list<string> $arguments
     *
     * @throws BranchNotPushed
     */
    private function finished(Process $process, array $arguments): Process
    {
        return $this->guardedRun->run($process, 'git '.($arguments[0] ?? ''));
    }
}
