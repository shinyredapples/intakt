<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Intakt\Plugin\Forge\Contract\ChangeTextHoldsSecret;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use NoDiscard;

interface BranchPush
{
    /**
     * @throws BranchNotPushed
     * @throws UnsignedCommitRefused
     * @throws ChangeTextHoldsSecret
     * @throws ForgeWriteFailed
     */
    #[NoDiscard('a merge-main outcome has to go back to the builder')]
    public function push(BranchPushRequest $request): PushedBranch|MainConflicts;
}
