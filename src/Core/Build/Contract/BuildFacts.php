<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Review\Contract\Labels;

// Only facts the loop knows about the build; nothing here comes from the builder's output, so a job cannot pick its own model.
final readonly class BuildFacts
{
    private const string TIER_LABEL_PREFIX = 'model:';

    public function __construct(
        public ?Tier $labelledTier,
        public Paths $paths,
        public ?Setback $setback = null,
    ) {
    }

    public static function fromLabels(Labels $labels, Paths $paths, ?Setback $setback = null): self
    {
        $tiers = [];

        foreach ($labels as $label) {
            if (!str_starts_with($label, self::TIER_LABEL_PREFIX)) {
                continue;
            }

            // Ignored, never guessed at, because a value that names no tier (a model, a vendor) must not pick one.
            $tier = Tier::tryFrom(substr($label, strlen(self::TIER_LABEL_PREFIX)));

            if (null !== $tier) {
                $tiers[] = $tier;
            }
        }

        return new self(array_last($tiers), $paths, $setback);
    }
}
