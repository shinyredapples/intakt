<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Domain;

use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\Severity;
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
     * @return list<T>
     */
    private function mergedBySpot(array $findings): array
    {
        // Several reviewers on one side often raise the same spot, at different severities or a line apart, so a spot counts once within the same window the match uses. The most severe one stands for it, because a panel acts on its strictest reading. A finding without a file or line has no spot, so it stays alone.
        $merged = [];
        foreach ($findings as $finding) {
            $index = null === $finding->file || null === $finding->line ? null : $this->sameSpot($finding, $merged);
            if (null === $index) {
                $merged[] = $finding;
                continue;
            }

            if ($this->isMoreSevere($finding->severity, $merged[$index]->severity)) {
                $merged[$index] = $finding;
            }
        }

        return $merged;
    }

    /**
     * @param list<LoopFinding|Finding> $merged
     */
    private function sameSpot(LoopFinding|Finding $finding, array $merged): ?int
    {
        foreach ($merged as $index => $kept) {
            if ($kept->file === $finding->file && null !== $kept->line && null !== $finding->line && abs($kept->line - $finding->line) <= self::LINE_WINDOW) {
                return $index;
            }
        }

        return null;
    }

    private function isMoreSevere(Severity $severity, Severity $than): bool
    {
        return array_search($severity, Severity::cases(), true) < array_search($than, Severity::cases(), true);
    }

    /**
     * @param list<LoopFinding> $loopFindings
     * @param list<Finding>     $intaktFindings
     *
     * @return array{list<FindingMatch>, list<LoopFinding>, list<Finding>}
     */
    private function match(array $loopFindings, array $intaktFindings): array
    {
        $matches = [];
        $loopOnly = [];
        foreach ($loopFindings as $loopFinding) {
            $index = $this->closest($loopFinding, $intaktFindings);
            if (null === $index) {
                $loopOnly[] = $loopFinding;
                continue;
            }

            $matches[] = new FindingMatch($loopFinding, $intaktFindings[$index]);
            unset($intaktFindings[$index]);
        }

        return [$matches, $loopOnly, array_values($intaktFindings)];
    }

    /**
     * @param array<int, Finding> $intaktFindings
     */
    private function closest(LoopFinding $loopFinding, array $intaktFindings): ?int
    {
        $closest = null;
        $distance = self::LINE_WINDOW + 1;
        foreach ($intaktFindings as $index => $finding) {
            if ($finding->file !== $loopFinding->file || null === $finding->line) {
                continue;
            }

            $apart = abs($finding->line - $loopFinding->line);
            if ($apart < $distance) {
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
