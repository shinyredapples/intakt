<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

use Intakt\Core\Review\Contract\PanelVerdict;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the tool builds it per call, not the container.
#[Exclude]
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
