<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

// A rebase or a bisect detaches a worktree's HEAD, so the worktree listing names no branch, yet finishing or aborting it writes the branch back; git branch -d refuses such a branch for the same reason.
final readonly class RebasesAndBisects
{
    private const string HEAD_NAME = '/head-name';

    public function __construct(private WorktreeGitDirectories $directories = new WorktreeGitDirectories())
    {
    }

    /**
     * @return list<string>
     *
     * @throws ForgeReadFailed
     */
    public function branchesHeldIn(string $common): array
    {
        return array_merge(...array_map($this->branchesHeldBy(...), $this->directories->of($common)));
    }

    /**
     * @return list<string>
     */
    private function branchesHeldBy(string $directory): array
    {
        return array_values(array_filter(
            [$this->mergeRebase($directory.'/rebase-merge'), $this->applyRebase($directory.'/rebase-apply'), $this->bisect($directory.'/BISECT_START')],
            is_string(...),
        ));
    }

    // Read whenever the directory exists, because git writes head-name with it, so a missing one is a state that cannot be told.
    private function mergeRebase(string $state): ?string
    {
        return is_dir($state) ? $this->startedOn($state) : null;
    }

    // git am also uses rebase-apply and keeps HEAD on its branch, where the listing names it; only the rebasing or applying marker tells the two apart.
    private function applyRebase(string $state): ?string
    {
        if (!is_dir($state) || file_exists($state.'/applying') && !file_exists($state.'/rebasing')) {
            return null;
        }

        if (!file_exists($state.'/rebasing')) {
            throw new ForgeReadFailed(sprintf('"%s" holds neither a rebase nor a git am, so which branch it holds cannot be told', $state));
        }

        return $this->startedOn($state);
    }

    // The short name is made a full one, because BISECT_START holds the short name of the branch the bisect started on (or a hash, which names no branch).
    private function bisect(string $file): ?string
    {
        return file_exists($file) ? 'refs/heads/'.trim($this->read($file)) : null;
    }

    // An empty head-name cannot tell which branch the rebase writes back, while git writes "detached HEAD" for a rebase that started on no branch.
    private function startedOn(string $state): ?string
    {
        $name = trim($this->read($state.self::HEAD_NAME));

        if ('' === $name) {
            throw new ForgeReadFailed(sprintf('a rebase is under way in "%s", but its head-name is empty, so which branch it holds cannot be told', $state));
        }

        return 'detached HEAD' === $name ? null : $name;
    }

    private function read(string $path): string
    {
        try {
            return new Filesystem()->readFile($path);
        } catch (IOException $unread) {
            throw new ForgeReadFailed(sprintf('a rebase or a bisect is under way, but which branch it holds cannot be read: %s', $unread->getMessage()), $unread->getCode(), previous: $unread);
        }
    }
}
