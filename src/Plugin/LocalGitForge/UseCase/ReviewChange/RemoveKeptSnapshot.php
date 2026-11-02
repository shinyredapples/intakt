<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\RoundJudged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

// Removes the snapshot startOf() kept for a round once the round is judged, because nothing reads it after the judge and every start keeps one.
#[AsEventListener]
final readonly class RemoveKeptSnapshot
{
    public function __construct(private KeptSnapshots $snapshots)
    {
    }

    public function __invoke(RoundJudged $judged): void
    {
        $this->snapshots->remove($judged->roundId, $judged->checkoutRoot);
    }
}
