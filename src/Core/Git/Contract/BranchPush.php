<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Intakt\Plugin\Forge\Contract\ChangeId;
use Intakt\Plugin\Forge\Contract\ChangeTextHoldsSecret;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use NoDiscard;

interface BranchPush
{
    /**
     * @throws BranchNotPushed
     * @throws UnsignedCommitRefused
     * @throws ForgeWriteFailed
     */
    #[NoDiscard('a merge-main outcome has to go back to the builder, and a refused push to its caller')]
    public function push(BranchPushRequest $request): PushedBranch|MainConflicts|RefusedPush;

    /**
     * @throws BranchNotPushed
     * @throws ChangeTextHoldsSecret
     * @throws ForgeWriteFailed
     */
    public function openChange(BranchPushRequest $request, PushedBranch $pushed): ChangeId;
}
