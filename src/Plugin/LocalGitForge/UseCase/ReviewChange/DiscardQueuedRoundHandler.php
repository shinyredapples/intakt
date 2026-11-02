<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\DiscardQueuedRound;

#[AsCommandHandler]
final readonly class DiscardQueuedRoundHandler
{
    public function __construct(private KeptSnapshots $snapshots)
    {
    }

    public function __invoke(DiscardQueuedRound $command): void
    {
        $this->snapshots->remove($command->round->roundId, $command->round->checkoutRoot);
    }
}
