<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

interface ReviewProgress
{
    // Called on every poll while a round is awaited, so a caller can tell a long wait from a stuck one.
    public function reached(RoundProgress $progress): void;
}
