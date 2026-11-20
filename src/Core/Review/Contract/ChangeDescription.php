<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Git\Contract\Paths;

final readonly class ChangeDescription
{
    public const string FULL_REVIEW_LABEL = 'review:full';

    /**
     * @param non-negative-int $lines
     * @param list<string>     $labels
     */
    public function __construct(
        public int $lines,
        public Paths $paths,
        public array $labels,
    ) {
    }

    public function wantsFullReview(): bool
    {
        return in_array(self::FULL_REVIEW_LABEL, $this->labels, true);
    }
}
