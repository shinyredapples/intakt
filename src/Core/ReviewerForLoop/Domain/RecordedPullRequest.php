<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Domain;

use Closure;
use Intakt\Core\ReviewerForLoop\Contract\PullRequestComment;

final readonly class RecordedPullRequest
{
    /**
     * @param list<PostedComment> $postedComments
     */
    public function __construct(
        public int $number,
        public string $loopIdentity,
        public string $intaktIdentity,
        private array $postedComments,
    ) {
    }

    /**
     * @param Closure(PullRequestComment): bool $isReview
     */
    public function keepingReviews(Closure $isReview): self
    {
        $reviews = array_filter($this->postedComments, static fn (PostedComment $posted): bool => $isReview($posted->comment));

        return new self($this->number, $this->loopIdentity, $this->intaktIdentity, array_values($reviews));
    }

    /**
     * @return list<PullRequestComment>
     */
    public function comments(): array
    {
        return array_map(static fn (PostedComment $posted): PullRequestComment => $posted->comment, $this->postedComments);
    }

    // A head belongs to the week of its last review, so the two sides' reviews of one head are never split between two reports.
    /**
     * @return list<string>
     */
    public function headsReviewedIn(Week $week): array
    {
        $lastReviews = [];
        foreach ($this->postedComments as $posted) {
            if ($this->isReview($posted->comment)) {
                $head = $posted->comment->head;
                $lastReviews[$head] = max($lastReviews[$head] ?? $posted->postedAt, $posted->postedAt);
            }
        }

        $inWeek = array_filter($lastReviews, $week->holds(...));

        // Cast back, because PHP turns a head of digits only into an integer key.
        return array_map(strval(...), array_keys($inWeek));
    }

    private function isReview(PullRequestComment $comment): bool
    {
        return in_array($comment->author, [$this->loopIdentity, $this->intaktIdentity], true);
    }
}
