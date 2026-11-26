<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Budget\Contract\Cost;

// One round's figure carries its round id, because an array keyed by round ids turns a numeric id into an int key.
final readonly class RoundCost
{
    public function __construct(
        public string $roundId,
        public Cost $cost,
    ) {
    }
}
