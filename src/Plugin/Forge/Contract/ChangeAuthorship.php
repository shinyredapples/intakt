<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Asked of the forge, because only the forge sets who created a change, and title, labels and branch are text others can shape: docs/architecture/handed-to-intakt.md
interface ChangeAuthorship
{
    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function isCreatedByIntakt(ChangeId $change): bool;
}
