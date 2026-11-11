<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the review builds it per poll, not the container; the flag is data and not a mode.
#[Exclude]
final readonly class RoundProgress
{
    // $finished holds each finished reviewer's state in words by reviewer id, so the page can stop showing it running.
    /**
     * @param array<string, string> $finished
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
