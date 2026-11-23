<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Apart from the reads, because a read-only forge (the local git one) has nothing to write.
interface ForgeWrites
{
    /**
     * @throws ReviewTextHoldsSecret
     * @throws RoundStale
     * @throws ForgeWriteFailed
     */
    public function postReview(string $project, Change $change, ReviewComment $comment): void;

    // False when the forge publishes nothing by its configuration (an install without a GitHub App), so a caller records nothing the pull request does not show.
    /**
     * @throws ReviewTextHoldsSecret
     * @throws ChangeNotFound
     * @throws ForgeWriteFailed
     */
    public function publishVerdict(string $project, ChangeId $change, PublishedVerdict $verdict): bool;
}
