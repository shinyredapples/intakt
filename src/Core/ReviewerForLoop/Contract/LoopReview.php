<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

final readonly class LoopReview
{
    /**
     * @param list<LoopFinding> $findings
     */
    public function __construct(
        public string $reviewer,
        public LoopVerdict $verdict,
        public array $findings,
    ) {
    }
}
