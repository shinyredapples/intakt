<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Apart from Forge and ForgeWrites, because only a forge with labels has a status to mirror.
interface StatusLabels
{
    // A person's status label is replaced and said so once, because the status mirrors the round and a hand-set label would mislead the list.
    /**
     * @throws ChangeNotFound
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    public function mirror(string $project, ChangeId $change, RoundStatusLabel $status): void;

    // The status Intakt set last is put back when a person changed it since, because a change between two round steps would otherwise stand until the next step.
    /**
     * @throws ChangeNotFound
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    public function restore(string $project, ChangeId $change): void;
}
