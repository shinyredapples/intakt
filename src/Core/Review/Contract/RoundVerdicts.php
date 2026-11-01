<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class RoundVerdicts
{
    /**
     * @param list<PanelVerdict> $counting
     */
    public function __construct(
        public VerdictStanding $standing,
        public array $counting,
    ) {
    }
}
