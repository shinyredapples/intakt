<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the review builds it per poll, not the container; the flag is data and not a mode.
#[Exclude]
final readonly class RoundProgress
{
    // $finished holds each finished reviewer's state by reviewer id, so the page can stop showing it running; $awaited lists the reviewers not finished yet, in panel order, so the demo can say what it waits on; $blocked lists the finished ones that ended blocked, so the demo finds one blocked before its first poll; $findingsToJudge is false once the panel reported none, because the judge then completes the round without a model call.
    /**
     * @param array<string, FinishedState> $finished
     * @param list<AwaitedReviewer>        $awaited
     * @param list<AwaitedReviewer>        $blocked
     */
    public function __construct(
        public int $reviewersFinished,
        public int $reviewers,
        public bool $judged,
        public array $finished = [],
        public int $reviewersStarted = 0,
        public array $awaited = [],
        public bool $findingsToJudge = true,
        public array $blocked = [],
    ) {
    }

    public function withNothingToJudge(): self
    {
        return clone ($this, ['findingsToJudge' => false]);
    }

    // Awaited reviewers compare by id, because each poll builds its own objects; the blocked ones follow from $finished.
    public function isSameAs(?self $other): bool
    {
        return $other instanceof self && $other->reviewersStarted === $this->reviewersStarted && $other->reviewersFinished === $this->reviewersFinished && $other->reviewers === $this->reviewers && $other->judged === $this->judged && $other->finished === $this->finished && $other->findingsToJudge === $this->findingsToJudge && array_column($other->awaited, 'id') === array_column($this->awaited, 'id');
    }
}
