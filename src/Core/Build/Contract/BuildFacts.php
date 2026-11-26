<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\EscalationOutcome;
use Intakt\Core\Review\Contract\Labels;

// Only facts the loop knows about the build; nothing here comes from the builder's output, so a job cannot pick its own model.
final readonly class BuildFacts
{
    public function __construct(
        public ?Tier $labelledTier,
        public Paths $paths,
        public ?Setback $setback = null,
        // The escalation outcome of the change's latest review round, read from the ledger, because a confirmed escalation sends the next build to the strong tier.
        public ?EscalationOutcome $escalation = null,
    ) {
    }

    public static function fromLabels(Labels $labels, Paths $paths, ?Setback $setback = null): self
    {
        $tiers = [];

        foreach ($labels as $label) {
            $tier = Tier::fromLabel($label);

            if ($tier instanceof Tier) {
                $tiers[] = $tier;
            }
        }

        return new self(array_last($tiers), $paths, $setback);
    }

    public function afterEscalation(?EscalationOutcome $escalation): self
    {
        return clone ($this, ['escalation' => $escalation]);
    }
}
