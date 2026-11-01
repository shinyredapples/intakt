<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Watcher\UseCase\FindChangedPullRequests;

use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\OpenChangeListing;
use Intakt\Plugin\Forge\Contract\OpenChanges;

final class FailingForge implements OpenChanges
{
    public function listOpen(string $project, ?string $validator): OpenChangeListing
    {
        throw new ForgeReadFailed('The forge is down.');
    }
}
