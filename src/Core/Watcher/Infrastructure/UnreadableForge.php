<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Infrastructure;

use Intakt\Plugin\Forge\Contract\CiState;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\HeadChecks;
use Intakt\Plugin\Forge\Contract\OpenChangeListing;
use Intakt\Plugin\Forge\Contract\OpenChanges;

// Stands in until GitHubForge lists pull requests and reads CI (plan task T18), so the tick boots and fails loudly; delete it when GitHubForge implements both interfaces.
final readonly class UnreadableForge implements OpenChanges, HeadChecks
{
    public function listOpen(string $project, ?string $validator): OpenChangeListing
    {
        throw $this->unreadable();
    }

    public function ciStateOf(string $project, CommitId $head): CiState
    {
        throw $this->unreadable();
    }

    private function unreadable(): ForgeReadFailed
    {
        return new ForgeReadFailed('No forge reads pull requests and CI yet.');
    }
}
