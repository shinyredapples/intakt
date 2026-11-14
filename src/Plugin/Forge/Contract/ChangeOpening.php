<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Apart from ForgeWrites, because opening a change needs the project's repository and a pushed branch, which the review writes never do; a repeated call opens no second change, because it looks for the branch's open one first.
interface ChangeOpening
{
    /**
     * @throws ChangeTextHoldsSecret
     * @throws ForgeWriteFailed
     */
    public function openOrUpdate(ProposedChange $change): ChangeId;
}
