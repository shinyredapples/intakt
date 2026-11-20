<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Apart from ForgeWrites, because opening a change needs the project's repository and a pushed branch, which the review writes never do; a repeated call opens no second change, because it looks for the branch's open one first.
interface ChangeOpening
{
    /**
     * Reads only, and is asked before the branch is pushed, because new commits on a branch are a write to the open change another party opened on it.
     *
     * @throws ForgeWriteFailed when the branch's open change was opened by another party, or its opener cannot be checked
     */
    public function refuseForeignChange(string $project, string $branch): void;

    /**
     * @throws ChangeTextHoldsSecret
     * @throws ForgeWriteFailed
     */
    public function openOrUpdate(ProposedChange $change): ChangeId;
}
