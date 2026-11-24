<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Apart from Forge and ForgeWrites, because only a forge with labels has a status to mirror.
interface StatusLabels
{
    // Leaves exactly one status label on the change. A status label a person put there is replaced and the change gets one comment about it.
    /**
     * @throws ChangeNotFound
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    public function mirror(string $project, ChangeId $change, RoundStatusLabel $status): void;
}
