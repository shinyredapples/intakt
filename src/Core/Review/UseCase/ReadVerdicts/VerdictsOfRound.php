<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadVerdicts;

use Intakt\Core\Review\Contract\VerdictIdentity;
use Intakt\Plugin\Forge\Contract\HeadMove;

// The move comes with the query because only the forge can read it: the move from the round's head to the current one.
final readonly class VerdictsOfRound
{
    public function __construct(
        public string $roundId,
        public VerdictIdentity $current,
        public HeadMove $move,
    ) {
    }
}
