<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Apart from the reads, because a read-only forge (the local git one) has nothing to write and a shadow project must reach none of these.
interface ForgeWrites
{
    /**
     * @throws ReviewTextHoldsSecret
     * @throws RoundStale
     * @throws ForgeWriteFailed
     */
    public function postReview(string $project, Change $change, ReviewComment $comment): void;
}
