<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeOpening;
use Intakt\Plugin\Forge\Contract\OpenedChange;
use Intakt\Plugin\Forge\Contract\Opening;
use Intakt\Plugin\Forge\Contract\ProposedChange;

// Answers the branch's own name as the change, so the evidence of intakt:build:task pushes to a local git remote without GitHub; GitHubChangeOpener opens the pull request of an installed instance.
final readonly class LocalChangeOpening implements ChangeOpening
{
    // A local git remote has no pull requests, so none of them belongs to another account.
    public function refuseForeignChange(ProposedChange $change): void
    {
    }

    public function openOrUpdate(ProposedChange $change): OpenedChange
    {
        return new OpenedChange(new ChangeId(sprintf('%s:%s', $change->project, $change->branch)), Opening::Created);
    }
}
