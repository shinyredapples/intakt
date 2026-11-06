<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\PanelVerdict;

// The count, not the findings, because the act list already shows the ones that matter.
final readonly class ReviewOutputVerdict
{
    public function __construct(
        public Tier $tier,
        public Decision $decision,
        public int $findings,
    ) {
    }

    public static function of(PanelVerdict $verdict): self
    {
        return new self($verdict->tier, $verdict->verdict->verdict, count($verdict->verdict->findings));
    }
}
