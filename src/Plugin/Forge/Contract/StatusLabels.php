<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Apart from Forge and ForgeWrites, because only a forge with labels has a status to mirror.
interface StatusLabels
{
    // One step for every caller, because the status comes from the stored round and never from the caller, so a late or repeated call cannot leave an older status; a person's change it undoes is said so once.
    /**
     * @throws ChangeNotFound
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    public function reconcile(string $project, ChangeId $change): void;
}
