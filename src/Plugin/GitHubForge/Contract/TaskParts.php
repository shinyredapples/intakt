<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use NoDiscard;

interface TaskParts
{
    // Once per marker, because a forge can create an issue and still answer as failed, and the split's retry must not file the part twice: the issues filed since the plan are read first, and one of this App whose body carries the marker stands for the create. A failed read files nothing.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    #[NoDiscard('the number is what later parts wait for')]
    public function fileOnce(string $project, PartIssue $part): int;
}
