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
        $loopFindings = $this->mergedBySpot(array_map(static fn (LoopReview $review): array => $review->findings, $loop->reviews));
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
     * @param list<list<T>> $reviews
     *
     * @return list<MergedSpot<T>>
     */
    private function mergedBySpot(array $reviews): array
    {
        // Several reviewers on one side often raise the same spot, at different severities or a line apart, so a spot counts once within the same window the match uses. Sorted by file and line, a finding joins the spot before it when the spot admits it, so the spots do not depend on the reviewers' order.
        $spots = [];
        foreach ($this->byFileAndLine($reviews) as $sameLine) {
            $spots = $this->placed($sameLine, $spots);
        }

        return $spots;
    }

    /**
     * @template T of LoopFinding|Finding
     *
     * @param non-empty-list<array{int, T}> $sameLine
     * @param list<MergedSpot<T>>           $spots
     *
     * @return list<MergedSpot<T>>
     */
    private function placed(array $sameLine, array $spots): array
    {
        // Findings on one line tie in the sort, so every one the spot admits joins it before any of them opens a new spot; otherwise the reviewers' order would decide which spots form.
        $last = array_pop($spots);
        $waiting = [];
        foreach ($sameLine as [$review, $finding]) {
            if (null !== $last && $last->admits($finding, $review)) {
                $last = $last->joinedBy($finding, $review);
                continue;
            }

            $waiting[] = [$review, $finding];
        }

        $spots = null === $last ? $spots : [...$spots, $last];
        if ([] === $waiting) {
            return $spots;
        }

        [$review, $finding] = array_shift($waiting);
        $spots[] = MergedSpot::of($finding, $review);

        return [] === $waiting ? $spots : $this->placed($waiting, $spots);
    }

    private function withinWindow(?int $linesApart): bool
    {
        return null !== $linesApart && $linesApart <= self::LINE_WINDOW;
    }

    /**
     * @template T of LoopFinding|Finding
     *
     * @param list<list<T>> $reviews
     *
     * @return list<non-empty-list<array{int, T}>>
     */
    private function byFileAndLine(array $reviews): array
    {
        $tagged = [];
        foreach ($reviews as $review => $findings) {
            foreach ($findings as $finding) {
                $tagged[] = [$review, $finding];
            }
        }

        $spot = static fn (array $entry): array => [$entry[1]->file ?? '', $entry[1]->line ?? 0];
        usort($tagged, static fn (array $a, array $b): int => $spot($a) <=> $spot($b));

        $groups = [];
        foreach ($tagged as $entry) {
            $previous = array_last($groups);
            if (null !== $previous && $spot($previous[0]) === $spot($entry)) {
                $groups[array_key_last($groups)][] = $entry;
                continue;
            }

            $groups[] = [$entry];
        }

        return $groups;
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
     * @param MergedSpot<LoopFinding>         $loopSpot
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
     * @return list<list<Finding>>
     */
    private function countingFindings(array $shadowRounds): array
    {
        $reviews = [];
        foreach ($this->verdicts($shadowRounds) as $verdict) {
            if (!$verdict->verdict->isFailedReview()) {
                $reviews[] = $verdict->verdict->findings;
            }
        }

        return $reviews;
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
