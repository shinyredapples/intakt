<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Ai\Contract\Provider;
use Intakt\Core\Ai\Contract\Tier;

final readonly class PanelReviewer
{
    /**
     * @param non-empty-string $role
     * @param non-empty-string $prompt
     * @param positive-int     $budget
     */
    public function __construct(
        public string $role,
        public Tier $tier,
        public Provider $provider,
        public string $prompt,
        public int $budget,
    ) {
    }
}
