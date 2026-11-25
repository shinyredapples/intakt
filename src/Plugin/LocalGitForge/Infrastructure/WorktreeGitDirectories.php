<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\Infrastructure;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

// The common directory holds the main worktree's state and worktrees/<id> each linked one's, so a guard over every worktree reads all of them.
final readonly class WorktreeGitDirectories
{
    /**
     * @return list<string>
     */
    public function of(string $common): array
    {
        if (!is_dir($common.'/worktrees')) {
            return [$common];
        }

        $worktrees = Finder::create()->directories()->depth(0)->ignoreDotFiles(false)->ignoreVCS(false)->in($common.'/worktrees');

        return [$common, ...array_map(static fn (SplFileInfo $worktree): string => $worktree->getPathname(), iterator_to_array($worktrees, false))];
    }
}
