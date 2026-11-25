<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Apart from Forge and ForgeWrites, because only a forge with labels has a status to mirror.
interface StatusLabels
{
    // Every read comes before the first write, so a read that fails writes nothing; a person's change it undoes is said so in one comment.
    /**
     * @throws ChangeNotFound
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    public function match(string $project, ChangeId $change, ?RoundStatusLabel $wanted): void;
}
