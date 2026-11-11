<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the review builds it per poll, not the container; the flag is data and not a mode.
#[Exclude]
final readonly class RoundProgress
{
    /**
     * @param array<string, string> $finished the state in words of each finished reviewer, by reviewer id
     */
    public function __construct(
        public int $reviewersFinished,
        public int $reviewers,
        public bool $judged,
        public array $finished = [],
    ) {
    }

    public function isSameAs(?self $other): bool
    {
        return $other instanceof self && $other->reviewersFinished === $this->reviewersFinished && $other->reviewers === $this->reviewers && $other->judged === $this->judged && $other->finished === $this->finished;
    }
}
