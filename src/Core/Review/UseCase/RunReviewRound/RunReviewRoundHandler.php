<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;

#[AsCommandHandler]
final readonly class RunReviewRoundHandler
{
    public function __construct(
        private ShadowRoundHold $shadowHold,
        private RoundOpening $opening,
    ) {
    }

    // Returns the id of the round because it names the rows and ledger events a caller reads back.
    public function __invoke(RunReviewRound $command): string
    {
        // Before the round's transaction, because the ledger event of a held round must stay when no round is stored.
        $this->shadowHold->refuseAboveTheCeiling($command);

        return $this->opening->open($command);
    }
}
