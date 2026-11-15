<?php

declare(strict_types=1);

namespace Intakt\Core\Bench\Contract;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Review\Contract\RoundTokens;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the bench builds it, not the container.
#[Exclude]
final readonly class BenchReviewerResult
{
    public function __construct(
        public string $role,
        public Tier $tier,
        // Holds the matched defects as caught, the false alarms and the harness failure of this reviewer's run.
        public RunVerdict $verdict,
        public RoundTokens $tokens,
        public ?int $milliseconds,
    ) {
    }
}
