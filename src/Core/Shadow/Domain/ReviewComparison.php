<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Domain;

use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Shadow\Contract\FindingMatch;
use Intakt\Core\Shadow\Contract\HeadComparison;
use Intakt\Core\Shadow\Contract\LoopFinding;
use Intakt\Core\Shadow\Contract\LoopReview;
use Intakt\Core\Shadow\Contract\LoopReviews;
use Intakt\Core\Shadow\Contract\LoopVerdict;
use Intakt\Core\Shadow\Contract\ReviewerDecision;
use Intakt\Core\Shadow\Contract\ReviewOutcome;
use NoDiscard;

final readonly class ReviewComparison
{
    // Two findings about the same spot rarely agree on the exact line, so a few lines either way still match.
    public const int LINE_WINDOW = 3;

    /**
     * @param list<ChangeReview> $shadowRounds
     */
    #[NoDiscard('a comparison that is not read reports nothing')]
    public function compare(string $head, LoopReviews $loop, array $shadowRounds): HeadComparison
    {
        $loopFindings = $this->mergedBySpot(array_merge(...array_map(static fn (LoopReview $review): array => $review->findings, $loop->reviews)));
        $intaktFindings = $this->mergedBySpot($this->countingFindings($shadowRounds));
        [$matches, $loopOnly, $intaktOnly] = $this->match($loopFindings, $intaktFindings);

        return new HeadComparison(
            head: $head,
            loopDecisions: array_map($this->loopDecision(...), $loop->reviews),
            intaktDecisions: $this->intaktDecisions($shadowRounds),
            matches: $matches,
            loopOnly: $loopOnly,
            intaktOnly: $intaktOnly,
            intaktTokens: array_sum(array_map(static fn (ChangeReview $round): int => $round->tokens->total, $shadowRounds)),
        );
    }

    /**
     * @template T of LoopFinding|Finding
     *
     * @param list<T> $findings
     *
     * @return list<MergedSpot<T>>
     */
    private function mergedBySpot(array $findings): array
    {
        // Several reviewers on one side often raise the same spot, at different severities or a line apart, so a spot counts once within the same window the match uses. Sorted by file and line, a finding joins the spot before it when it is within the window, so the spots do not depend on the reviewers' order. A finding without a file or line has no spot, so it stays alone.
        $spots = [];
        foreach ($this->byFileAndLine($findings) as $finding) {
            $last = array_last($spots);
            if (null !== $last && $this->withinWindow($last->linesApart(MergedSpot::of($finding)))) {
                $spots[array_key_last($spots)] = $last->joinedBy($finding);
                continue;
            }

            $spots[] = MergedSpot::of($finding);
        }

        return $spots;
    }

    private function withinWindow(?int $linesApart): bool
    {
        return null !== $linesApart && $linesApart <= self::LINE_WINDOW;
    }

    /**
     * @template T of LoopFinding|Finding
     *
     * @param list<T> $findings
     *
     * @return list<T>
     */
    private function byFileAndLine(array $findings): array
    {
        usort($findings, static fn (LoopFinding|Finding $a, LoopFinding|Finding $b): int => [$a->file ?? '', $a->line ?? 0] <=> [$b->file ?? '', $b->line ?? 0]);

        return $findings;
    }

    /**
     * @param list<MergedSpot<LoopFinding>> $loopSpots
     * @param list<MergedSpot<Finding>>     $intaktSpots
     *
     * @return array{list<FindingMatch>, list<LoopFinding>, list<Finding>}
     */
    private function match(array $loopSpots, array $intaktSpots): array
    {
        $matches = [];
        $loopOnly = [];
        foreach ($loopSpots as $loopSpot) {
            $index = $this->closest($loopSpot, $intaktSpots);
            if (null === $index) {
                $loopOnly[] = $loopSpot->finding;
                continue;
            }

            $matches[] = new FindingMatch($loopSpot->finding, $intaktSpots[$index]->finding);
            unset($intaktSpots[$index]);
        }

        return [$matches, $loopOnly, array_map(static fn (MergedSpot $spot): Finding => $spot->finding, array_values($intaktSpots))];
    }

    /**
     * @param MergedSpot<LoopFinding>          $loopSpot
     * @param array<int, MergedSpot<Finding>> $intaktSpots
     */
    private function closest(MergedSpot $loopSpot, array $intaktSpots): ?int
    {
        $closest = null;
        $distance = self::LINE_WINDOW + 1;
        foreach ($intaktSpots as $index => $spot) {
            $apart = $loopSpot->linesApart($spot);
            if ($this->withinWindow($apart) && $apart < $distance) {
                $closest = $index;
                $distance = $apart;
            }
        }

        return $closest;
    }

    /**
     * @param list<ChangeReview> $shadowRounds
     *
     * @return list<Finding>
     */
    private function countingFindings(array $shadowRounds): array
    {
        $findings = [];
        foreach ($this->verdicts($shadowRounds) as $verdict) {
            if (!$verdict->verdict->isFailedReview()) {
                array_push($findings, ...$verdict->verdict->findings);
            }
        }

        return $findings;
    }

    /**
     * @param list<ChangeReview> $shadowRounds
     *
     * @return list<ReviewerDecision>
     */
    private function intaktDecisions(array $shadowRounds): array
    {
        $decisions = [];
        foreach ($shadowRounds as $round) {
            foreach ($round->verdicts as $verdict) {
                $decisions[] = new ReviewerDecision(sprintf('round %d %s', $round->round, $verdict->tier->value), $this->intaktOutcome($verdict));
            }
        }

        return $decisions;
    }

    /**
     * @param list<ChangeReview> $shadowRounds
     *
     * @return list<PanelVerdict>
     */
    private function verdicts(array $shadowRounds): array
    {
        return array_merge(...array_map(static fn (ChangeReview $round): array => $round->verdicts, $shadowRounds));
    }

    private function intaktOutcome(PanelVerdict $verdict): ReviewOutcome
    {
        if ($verdict->verdict->isFailedReview()) {
            return ReviewOutcome::Failed;
        }

        return match ($verdict->verdict->verdict) {
            Decision::Approve => ReviewOutcome::Approve,
            Decision::Changes => ReviewOutcome::Changes,
            Decision::Blocked => ReviewOutcome::Blocked,
        };
    }

    private function loopDecision(LoopReview $review): ReviewerDecision
    {
        return new ReviewerDecision($review->reviewer, match ($review->verdict) {
            LoopVerdict::Approve => ReviewOutcome::Approve,
            LoopVerdict::Changes => ReviewOutcome::Changes,
            LoopVerdict::Unreadable => ReviewOutcome::Unreadable,
        });
    }
}
