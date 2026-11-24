<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Bench\Domain\RunVerdict;
use Intakt\Core\Review\Contract\ModelNames;
use Intakt\Core\Review\Contract\RoundTokens;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the bench builds it, not the container.
#[Exclude]
final readonly class BenchReviewerResult
{
    public function __construct(
        public string $role,
        public Tier $tier,
        // Holds the caught defects, the false alarms and the harness failure together, because the classifier decides them from one run.
        public RunVerdict $verdict,
        public RoundTokens $tokens,
        public ?int $milliseconds,
        public ModelNames $models,
    ) {
    }

    // Named by role, tier and model, because the report groups a reviewer's runs per model that answered.
    public function name(): string
    {
        $models = [...$this->models];

        return sprintf('%s (%s, %s)', $this->role, $this->tier->value, match (count($models)) {
            0 => 'model not recorded',
            1 => 'model '.$models[0],
            default => 'models '.implode(' and ', $models),
        });
    }
}
