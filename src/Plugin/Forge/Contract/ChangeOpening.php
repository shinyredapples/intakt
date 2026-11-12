<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Apart from ForgeWrites, because opening a change needs the project's repository and a pushed branch, which the review writes never do.
interface ChangeOpening
{
    /**
     * Opens the pull request of the branch, or updates the open one, so a repeated call never opens a second.
     *
     * @throws ChangeTextHoldsSecret
     * @throws ForgeWriteFailed
     */
    public function openOrUpdate(string $project, string $branch, string $base, ChangeText $text): ChangeId;
}
