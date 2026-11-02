<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Shadow\Contract\ProjectMode;
use Intakt\Core\Shadow\Contract\ProjectModes;

#[AsCommandHandler]
final readonly class RunReviewRoundHandler
{
    public function __construct(
        private ProjectModes $modes,
        private ShadowRoundHold $shadowHold,
        private RoundOpening $opening,
    ) {
    }

    // Returns the id of the round because it names the rows and ledger events a caller reads back.
    public function __invoke(RunReviewRound $command): string
    {
        // Before the round's transaction, because the ledger event of a held round must stay when no round is stored.
        if (ProjectMode::Shadow === $this->modes->of($command->target->subject->project)) {
            $this->shadowHold->refuseAboveTheCeiling($command);
        }

        return $this->opening->open($command);
    }
}
