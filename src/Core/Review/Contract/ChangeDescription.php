<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ChangeDescription
{
    public const string FULL_REVIEW_LABEL = 'review:full';

    /**
     * @param non-negative-int $lines  added and removed lines together
     * @param list<string>     $paths  repository-relative paths of the changed files
     * @param list<string>     $labels
     */
    public function __construct(
        public int $lines,
        public array $paths,
        public array $labels,
    ) {
    }

    public function wantsFullReview(): bool
    {
        return in_array(self::FULL_REVIEW_LABEL, $this->labels, true);
    }
}
