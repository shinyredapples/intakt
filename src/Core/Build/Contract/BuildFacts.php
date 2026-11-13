<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use Intakt\Core\Ai\Contract\Tier;

// Only facts the loop knows about the build; nothing here comes from the builder's output, so a job cannot pick its own model.
final readonly class BuildFacts
{
    private const string TIER_LABEL_PREFIX = 'model:';

    /**
     * @param list<string> $paths
     */
    public function __construct(
        public ?Tier $labelledTier,
        public array $paths,
    ) {
    }

    /**
     * @param list<string> $labels
     * @param list<string> $paths
     */
    public static function fromLabels(array $labels, array $paths): self
    {
        $tiers = [];

        foreach ($labels as $label) {
            if (!str_starts_with($label, self::TIER_LABEL_PREFIX)) {
                continue;
            }

            // A value that names no tier (a model, a vendor) is ignored, never guessed at.
            $tier = Tier::tryFrom(substr($label, strlen(self::TIER_LABEL_PREFIX)));

            if (null !== $tier) {
                $tiers[] = $tier;
            }
        }

        return new self(array_last($tiers), $paths);
    }
}
