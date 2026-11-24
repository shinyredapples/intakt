<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Core\Git\Contract\HardenedGit;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\GitHubForge\Domain\CiWorkflows;
use Intakt\Plugin\GitHubForge\Domain\CommitNotRead;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Process\Process;

// Reads the workflows from git objects, never from a checkout, so the scan sees exactly what the forge runs for each commit.
final readonly class MirrorWorkflows
{
    private const string BRANCHES = 'refs/heads/';
    private const string WORKFLOWS = '.github/workflows';
    // GitHub runs the .yml and .yaml files directly in the folder; any case counts, so a spelling the forge may accept is never skipped.
    private const string WORKFLOW_NAME = '#^\.github/workflows/[^/]+\.ya?ml\z#i';
    private const string ENTRY = '/^(\d{6}) (\w+) ([0-9a-f]{40}|[0-9a-f]{64})\t(.*)\z/s';
    private const string REGULAR_FILE = '/^100(644|755)$/';
    private const string BLOB = 'blob';
    private const string TREE = 'tree';
    private const string FOLDER = '040000';
    // Because merge-tree exits with 1 when the two sides conflict, which is an answer, not a failure.
    private const int CONFLICT = 1;

    public function __construct(
        private HardenedGit $hardened,
    ) {
    }

    /**
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     */
    public function of(string $repository, string $head): CiWorkflows
    {
        $repository = $this->absolute($repository);
        $main = $this->commit($repository, $this->mainBranch($repository));
        $head = $this->commit($repository, $head);
        $merged = $this->merged($repository, $main, $head);

        return new CiWorkflows(
            $this->workflowsAt($repository, $head),
            $this->workflowsAt($repository, $main),
            null === $merged ? [WorkflowFile::unread(self::WORKFLOWS, 'the head does not merge with main without a conflict')] : $this->workflowsAt($repository, $merged),
            // Against the head, because pushing it runs its own files even where main already holds or has reverted them; against the merge too, because that is what the change brings to main.
            $this->changedPaths($repository, $main, $head, ...(null === $merged ? [] : [$merged])),
        );
    }

    /**
     * @throws CommitNotRead
     */
    private function absolute(string $repository): string
    {
        // Because git runs inside the repository, where a relative --git-dir would resolve a second time.
        $directory = getcwd();
        if (false === $directory) {
            throw CommitNotRead::because('the current directory is unknown, so the repository path does not resolve');
        }

        return Path::makeAbsolute($repository, $directory);
    }

    /**
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     */
    private function mainBranch(string $repository): string
    {
        $process = $this->run($repository, ['symbolic-ref', '--quiet', 'HEAD']);
        $branch = trim($process->getOutput());

        // Main is the branch the repository's HEAD names, because a mirror's HEAD follows the remote's default branch.
        if (!$process->isSuccessful() || !str_starts_with($branch, self::BRANCHES)) {
            throw CommitNotRead::because('the repository\'s HEAD names no branch, so main is unknown');
        }

        return $branch;
    }

    /**
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     */
    private function commit(string $repository, string $name): string
    {
        // Resolved once to a commit id, because a branch can move while the scan runs, and every later read must see the same commit.
        $process = $this->run($repository, ['rev-parse', '--verify', '--quiet', '--end-of-options', $name.'^{commit}']);
        $commit = trim($process->getOutput());

        if (!$process->isSuccessful() || '' === $commit) {
            throw CommitNotRead::because(sprintf('%s names no commit of the repository', $name));
        }

        return $commit;
    }

    /**
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     */
    private function merged(string $repository, string $main, string $head): ?string
    {
        $process = $this->run($repository, ['merge-tree', '--write-tree', '--no-messages', $main, $head]);

        return match ($process->getExitCode()) {
            0 => trim($process->getOutput()),
            self::CONFLICT => null,
            default => throw CommitNotRead::because(sprintf('git merge-tree failed: %s', trim($process->getErrorOutput()))),
        };
    }

    /**
     * @return list<WorkflowFile>
     *
     * @throws RepositoryAttributesRefused
     */
    private function workflowsAt(string $repository, string $tree): array
    {
        $blocked = $this->foldersOnTheWay($repository, $tree);
        if (null !== $blocked) {
            return $blocked;
        }

        $process = $this->run($repository, ['ls-tree', '-z', '--full-tree', $tree, '--', self::WORKFLOWS.'/']);
        if (!$process->isSuccessful()) {
            return [WorkflowFile::unread(self::WORKFLOWS, sprintf('git ls-tree failed: %s', trim($process->getErrorOutput())))];
        }

        $workflows = [];
        foreach (array_filter(explode("\0", $process->getOutput()), static fn (string $entry): bool => '' !== $entry) as $entry) {
            $workflows[] = $this->workflow($repository, $entry);
        }

        return array_values(array_filter($workflows));
    }

    /**
     * @return list<WorkflowFile>|null
     *
     * @throws RepositoryAttributesRefused
     */
    private function foldersOnTheWay(string $repository, string $tree): ?array
    {
        // Each folder on its own, because ls-tree lists nothing inside a link or a submodule, and an empty answer would read as no workflows.
        foreach ([dirname(self::WORKFLOWS), self::WORKFLOWS] as $folder) {
            $blocked = $this->folder($repository, $tree, $folder);
            if (null !== $blocked) {
                return $blocked;
            }
        }

        return null;
    }

    /**
     * @return list<WorkflowFile>|null
     *
     * @throws RepositoryAttributesRefused
     */
    private function folder(string $repository, string $tree, string $folder): ?array
    {
        $process = $this->run($repository, ['ls-tree', '-z', '--full-tree', $tree, '--', $folder]);
        if (!$process->isSuccessful()) {
            return [WorkflowFile::unread($folder, sprintf('git ls-tree failed: %s', trim($process->getErrorOutput())))];
        }

        $entry = rtrim($process->getOutput(), "\0");
        if ('' === $entry) {
            return [];
        }

        if (1 !== preg_match(self::ENTRY, $entry, $match) || $folder !== $match[4]) {
            return [WorkflowFile::unread($folder, 'git ls-tree printed an entry it does not read')];
        }

        [, $mode, $type] = $match;

        return self::TREE === $type && self::FOLDER === $mode ? null : [WorkflowFile::unread($folder, sprintf('a %s entry with mode %s, not a folder', $type, $mode))];
    }

    /**
     * @throws RepositoryAttributesRefused
     */
    private function workflow(string $repository, string $entry): ?WorkflowFile
    {
        if (1 !== preg_match(self::ENTRY, $entry, $match)) {
            return WorkflowFile::unread(self::WORKFLOWS, 'git ls-tree printed an entry it does not read');
        }

        [, $mode, $type, $object, $path] = $match;
        if (1 !== preg_match(self::WORKFLOW_NAME, $path)) {
            return null;
        }

        // A link or a submodule has no text of its own here, so it is unread rather than skipped.
        if (self::BLOB !== $type || 1 !== preg_match(self::REGULAR_FILE, $mode)) {
            return WorkflowFile::unread($path, sprintf('a %s entry with mode %s, not a regular file', $type, $mode));
        }

        $process = $this->run($repository, ['cat-file', 'blob', $object]);

        return $process->isSuccessful()
            ? new WorkflowFile($path, $process->getOutput())
            : WorkflowFile::unread($path, sprintf('git cat-file failed: %s', trim($process->getErrorOutput())));
    }

    /**
     * @return list<string>
     *
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     */
    private function changedPaths(string $repository, string $main, string ...$changes): array
    {
        $paths = [];
        foreach ($changes as $change) {
            $paths = [...$paths, ...$this->pathsBetween($repository, $main, $change)];
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     *
     * @throws CommitNotRead
     * @throws RepositoryAttributesRefused
     */
    private function pathsBetween(string $repository, string $main, string $change): array
    {
        $process = $this->run($repository, ['diff-tree', '-r', '-z', '--name-only', '--no-renames', '--ignore-submodules=none', $main, $change]);
        if (!$process->isSuccessful()) {
            throw CommitNotRead::because(sprintf('git diff-tree failed: %s', trim($process->getErrorOutput())));
        }

        return array_values(array_filter(explode("\0", $process->getOutput()), static fn (string $path): bool => '' !== $path));
    }

    /**
     * @param list<string> $arguments
     *
     * @throws RepositoryAttributesRefused
     */
    private function run(string $repository, array $arguments): Process
    {
        $call = $this->hardened->callInBareRepository($repository, ...$arguments);
        $process = new Process([...$call->command], $call->directory, [...$call->environment]);
        $process->run();

        return $process;
    }
}
