<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Apart from ForgeWrites, because opening a change needs the project's repository and a pushed branch, which the review writes never do; a repeated call opens no second change, because it looks for the branch's open one first.
interface ChangeOpening
{
    // Asked before the branch is pushed, because a push moves the head of every open change of the branch, also of one another account owns.
    /**
     * @throws ForgeWriteFailed
     */
    public function refuseForeignChange(ProposedChange $change): void;

    /**
     * @throws ChangeTextHoldsSecret
     * @throws ForgeWriteFailed
     */
    public function openOrUpdate(ProposedChange $change): OpenedChange;
}
