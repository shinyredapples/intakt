<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use NoDiscard;

interface TaskParts
{
    // Once per marker, because a forge can create an issue and still answer as failed, and the split's retry must not file the part twice: an issue number an earlier create returned is kept in the ledger and checked first (its marker written into it when missing), else the issues filed since the plan are read, and one of this App whose body carries the marker stands for the create. A failed read files nothing.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    #[NoDiscard('the number is what later parts wait for')]
    public function fileOnce(string $project, PartIssue $part): int;

    // Whether a filing of a part of this project kept this issue number, known from that moment on, because a split that stopped before its end has no other record of the parts it filed, and an edit can remove a part's marker.
    public function isFiledPart(string $project, int $issue): bool;
}
