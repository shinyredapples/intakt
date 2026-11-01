<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Review\Contract\PanelVerdict;

final readonly class RoundVerdicts
{
    /**
     * @param list<PanelVerdict> $verdicts
     */
    public function __construct(
        public string $roundId,
        public array $verdicts,
    ) {
    }
}
