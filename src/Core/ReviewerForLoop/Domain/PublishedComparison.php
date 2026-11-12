<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Domain;

use Intakt\Core\Shadow\Contract\Agreement;
use Intakt\Core\Shadow\Contract\LoopFinding;
use Intakt\Core\Shadow\Contract\LoopReview;
use Intakt\Core\Shadow\Contract\LoopReviews;
use Intakt\Core\Shadow\Contract\LoopVerdict;
use Intakt\Core\Shadow\Contract\PublishedHead;
use Intakt\Core\Shadow\Contract\PullRequestComment;
use Intakt\Core\Shadow\Contract\ReviewerDecision;
use Intakt\Core\Shadow\Contract\ReviewOutcome;
use Intakt\Core\Shadow\Contract\WeeklyReport;
use NoDiscard;

// Intakt's published comment follows the loop's own format (issue #1251, D1), so the loop's reader reads both sides.
final readonly class PublishedComparison
{
    private const string INTAKT_REVIEWER = 'intakt';
    private const string GPT_REVIEWER = 'gpt';

    public function __construct(
        private LoopReviewReader $reader,
        private ReviewComparison $comparison,
    ) {
    }

    /**
     * @param list<RecordedPullRequest> $pullRequests
     */
    #[NoDiscard('a report that is not read reports nothing')]
    public function report(array $pullRequests, Week $week): WeeklyReport
    {
        $heads = [];
        foreach (array_map($this->reviewsOf(...), $pullRequests) as $pullRequest) {
            foreach ($pullRequest->headsReviewedIn($week) as $head) {
                $heads[] = $this->compareHead($pullRequest, $head);
            }
        }

        return new WeeklyReport($week->from, $week->until, $heads);
    }

    // Selected before heads are placed, because another loop reviewer's vote or a bot's later message must neither change the comparison nor move a head to another week.
    private function reviewsOf(RecordedPullRequest $pullRequest): RecordedPullRequest
    {
        return $pullRequest->keepingReviews(fn (PullRequestComment $comment): bool => match ($comment->author) {
            $pullRequest->loopIdentity => self::GPT_REVIEWER === strtolower($this->reader->reviewerNamedIn($comment)),
            $pullRequest->intaktIdentity => $this->reader->holdsVerdict($comment),
            default => false,
        });
    }

    private function compareHead(RecordedPullRequest $pullRequest, string $head): PublishedHead
    {
        $loop = $this->reader->read($pullRequest->loopIdentity, $head, $pullRequest->comments());
        $intakt = $this->reader->read($pullRequest->intaktIdentity, $head, $pullRequest->comments());
        [$matches, $loopOnly, $intaktOnly] = $this->comparison->matchedSpots($this->findings($loop), $this->findings($intakt));

        return new PublishedHead(
            pullRequest: $pullRequest->number,
            head: $head,
            loopDecisions: array_map(static fn (LoopReview $review): ReviewerDecision => new ReviewerDecision($review->reviewer, ReviewOutcome::from($review->verdict->value)), $loop->reviews),
            intaktDecisions: array_map(static fn (LoopReview $review): ReviewerDecision => new ReviewerDecision(self::INTAKT_REVIEWER, ReviewOutcome::from($review->verdict->value)), $intakt->reviews),
            agreement: $this->agreement($loop, $intakt),
            matched: count($matches),
            loopOnly: $loopOnly,
            intaktOnly: $intaktOnly,
        );
    }

    /**
     * @return list<list<LoopFinding>>
     */
    private function findings(LoopReviews $side): array
    {
        return array_map(static fn (LoopReview $review): array => $review->findings, $side->reviews);
    }

    private function agreement(LoopReviews $loop, LoopReviews $intakt): Agreement
    {
        $loopVerdict = $this->sideVerdict($loop);
        $intaktVerdict = $this->sideVerdict($intakt);
        if (LoopVerdict::Unreadable === $loopVerdict || LoopVerdict::Unreadable === $intaktVerdict) {
            return Agreement::Incomplete;
        }

        return $loopVerdict === $intaktVerdict ? Agreement::Agree : Agreement::Disagree;
    }

    // A side without reviews or with one it could not read stays unread even beside a changes vote, so an unread review never counts as agreement; otherwise one changes vote holds the head back.
    private function sideVerdict(LoopReviews $side): LoopVerdict
    {
        $verdicts = array_map(static fn (LoopReview $review): LoopVerdict => $review->verdict, $side->reviews);

        return match (true) {
            [] === $verdicts, in_array(LoopVerdict::Unreadable, $verdicts, true) => LoopVerdict::Unreadable,
            in_array(LoopVerdict::Changes, $verdicts, true) => LoopVerdict::Changes,
            default => LoopVerdict::Approve,
        };
    }
}
