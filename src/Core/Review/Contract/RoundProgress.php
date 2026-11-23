<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the review builds it per poll, not the container; the flag is data and not a mode.
#[Exclude]
final readonly class RoundProgress
{
    // $finished holds each finished reviewer's state by reviewer id, so the page can stop showing it running; $awaited lists the reviewers not finished yet, in panel order, so the demo can say what it waits on; $findingsToJudge is false once the panel reported none, because the judge then completes the round without a model call.
    /**
     * @param array<string, FinishedState> $finished
     * @param list<AwaitedReviewer>        $awaited
     */
    public function __construct(
        public int $reviewersFinished,
        public int $reviewers,
        public bool $judged,
        public array $finished = [],
        public int $reviewersStarted = 0,
        public array $awaited = [],
        public bool $findingsToJudge = true,
    ) {
    }

    public function withNothingToJudge(): self
    {
        return clone ($this, ['findingsToJudge' => false]);
    }

    public function isSameAs(?self $other): bool
    {
        return $other instanceof self && $other->reviewersStarted === $this->reviewersStarted && $other->reviewersFinished === $this->reviewersFinished && $other->reviewers === $this->reviewers && $other->judged === $this->judged && $other->finished === $this->finished;
    }
}
