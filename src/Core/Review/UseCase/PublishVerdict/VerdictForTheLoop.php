<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Review\Contract\ChangeDecision;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\QuorumPolicy;
use Intakt\Core\Review\Contract\ReplayMark;
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
        // Before any vote is read, because a replay plays a recorded review back, so its votes were never given on this head and must never reach the forge as a verdict; before the stale mark too, because a replay was never published as a review, so it has nothing to withdraw.
        if (ReplayMark::Replay === $round->replayMark()) {
            return new PublishedVerdict($round->identity()->commits->head, HeadStatus::Error, 'Intakt does not count a replay as a review of this head.', null, $this->tokenOf($round));
        }

        // Before any vote is read, because the head was refused after the round started (it no longer boots), so an approval queued earlier must never reach the forge as a success.
        if ($round->hasStaleVerdicts()) {
            return new PublishedVerdict($round->identity()->commits->head, HeadStatus::Failure, 'Intakt withdrew its review: this head does not boot.', null, $this->tokenOf($round));
        }

        // Not reviewed, because only a judging that failed for good publishes a round before its judge transition, so the round has no act list.
        if (RoundState::Judged !== $round->state()) {
            return $this->unreviewed($round);
        }

        $counted = $this->ledger->votesOf($round->id()->toRfc4122());
        $votes = array_map(fn (RoundReviewer $reviewer): PanelVerdict => $this->voteOf($reviewer, $counted), $round->reviewers());
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

    // The round's id, so a retried publication finds the comment an earlier attempt posted.
    private function tokenOf(ReviewRound $round): ReviewToken
    {
        return new ReviewToken($round->id()->toRfc4122());
    }

    private function unreviewed(ReviewRound $round): PublishedVerdict
    {
        return new PublishedVerdict($round->identity()->commits->head, HeadStatus::Error, 'Intakt could not review this head.', null, $this->tokenOf($round));
    }

    // A reviewer without a verdict votes as blocked, so a failed review can never help a quorum; a verdict counts as the judge step recorded it, and as it was given when that step recorded no vote for it (a round judged before votes were counted).
    /**
     * @param array<string, Decision> $counted
     */
    private function voteOf(RoundReviewer $reviewer, array $counted): PanelVerdict
    {
        $given = $this->verdicts->ofReviewer($reviewer)?->verdict;
        $vote = $given instanceof Decision ? $counted[$reviewer->id()->toRfc4122()] ?? $given : Decision::Blocked;

        return new PanelVerdict($reviewer->tier(), new Verdict($vote, [], [], []));
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
        return $this->findings->actListOf($round, $this->ledger->judgeDoneOf($round->id()->toRfc4122()));
    }
}
