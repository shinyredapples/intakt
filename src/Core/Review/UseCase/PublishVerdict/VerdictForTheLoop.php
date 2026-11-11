<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Domain\ReviewRound;
use Intakt\Core\Review\Domain\RoundReviewer;
use Intakt\Core\Review\Domain\RoundState;
use Intakt\Core\Review\UseCase\ReadVerdicts\StoredVerdicts;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Core\Review\UseCase\RunReviewRound\StoredFindings;
use Intakt\Plugin\Forge\Contract\HeadStatus;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;
use Intakt\Plugin\Forge\Contract\ReviewToken;

// Decided like intakt:review decides, from the quorum and the stored verdicts alone, so the loop gets the outcome the panel's policy gives.
final readonly class VerdictForTheLoop
{
    public function __construct(
        private StoredVerdicts $verdicts,
        private StoredFindings $findings,
        private RoundLedgerReading $ledger,
        private ChangeDecision $decision,
    ) {
    }

    public function of(ReviewRound $round): PublishedVerdict
    {
        // Not reviewed, because only a judging that failed for good publishes a round before its judge transition, so the round has no act list.
        if (RoundState::Judged !== $round->state()) {
            return $this->unreviewed($round);
        }

        $votes = array_map($this->voteOf(...), $round->reviewers());
        $decision = $this->decisionOf($round, $votes);
        if (Decision::Blocked === $decision) {
            return $this->unreviewed($round);
        }

        $head = $round->identity()->commits->head;
        $comment = $this->commentOf($round, $decision, $votes);

        // From the comment's verdict, not the decision, so the status and the comment never tell the loop two outcomes.
        return Decision::Approve === $comment->verdict()
            ? new PublishedVerdict($head, HeadStatus::Success, 'Intakt approves this head.', $comment->text(), $this->tokenOf($round))
            : new PublishedVerdict($head, HeadStatus::Failure, 'Intakt asks for changes on this head.', $comment->text(), $this->tokenOf($round));
    }

    // The status alone, because a comment that would leak a secret must not reach the forge in any form.
    public function unpublishable(ReviewRound $round): PublishedVerdict
    {
        return new PublishedVerdict($round->identity()->commits->head, HeadStatus::Error, 'Intakt could not publish its review of this head.', null, $this->tokenOf($round));
    }

    // An error and no comment, because a verdict given in shadow mode must never count for the loop, even after the project left shadow mode.
    public function shadowed(ReviewRound $round): PublishedVerdict
    {
        return new PublishedVerdict($round->identity()->commits->head, HeadStatus::Error, 'Intakt reviewed this head in shadow mode, so its verdict does not count.', null, $this->tokenOf($round));
    }

    private function unreviewed(ReviewRound $round): PublishedVerdict
    {
        return new PublishedVerdict($round->identity()->commits->head, HeadStatus::Error, 'Intakt could not review this head.', null, $this->tokenOf($round));
    }

    // The round's id, so a retried publication finds the comment an earlier attempt posted.
    private function tokenOf(ReviewRound $round): ReviewToken
    {
        return new ReviewToken($round->id()->toRfc4122());
    }

    // A reviewer without a verdict votes as blocked, so a failed review can never help a quorum.
    private function voteOf(RoundReviewer $reviewer): PanelVerdict
    {
        return new PanelVerdict($reviewer->tier(), $this->verdicts->ofReviewer($reviewer) ?? new Verdict(Decision::Blocked, [], [], []));
    }

    /**
     * @param list<PanelVerdict> $votes
     */
    private function decisionOf(ReviewRound $round, array $votes): Decision
    {
        $quorum = $this->ledger->quorumOf($round->id()->toRfc4122());

        return $quorum instanceof QuorumPolicy ? $this->decision->of($quorum, $votes) : Decision::Blocked;
    }

    /**
     * @param list<PanelVerdict> $votes
     */
    private function commentOf(ReviewRound $round, Decision $decision, array $votes): LoopReviewComment
    {
        $act = $this->actListOf($round);
        $approvals = count(array_filter($votes, static fn (PanelVerdict $vote): bool => Decision::Approve === $vote->verdict->verdict));
        $summary = sprintf('%d of %d reviewers approve; findings to act on: %d.', $approvals, count($votes), count($act));

        return new LoopReviewComment($round->identity()->commits->head, $decision, $act, $summary);
    }

    /**
     * @return list<Finding>
     */
    private function actListOf(ReviewRound $round): array
    {
        $act = $this->ledger->actIdsOf($round->id()->toRfc4122());

        return array_values(array_filter($this->findings->reportedIn($round), static fn (Finding $finding): bool => in_array($finding->id, $act, true)));
    }
}
