<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Git\Contract\Paths;

final readonly class ChangeDescription
{
    public const string FULL_REVIEW_LABEL = 'review:full';

    /**
     * @param non-negative-int $lines
     */
    public function __construct(
        public int $lines,
        public Paths $paths,
        public Labels $labels,
    ) {
    }

    public function wantsFullReview(): bool
    {
        return $this->labels->contains(self::FULL_REVIEW_LABEL);
    }
}
