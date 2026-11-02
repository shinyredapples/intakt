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
        $loopFindings = $this->distinctLoopFindings(array_merge(...array_map(static fn (LoopReview $review): array => $review->findings, $loop->reviews)));
        $intaktFindings = $this->distinctIntaktFindings($this->countingFindings($shadowRounds));
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
     * @param list<LoopFinding> $findings
     *
     * @return list<LoopFinding>
     */
    private function distinctLoopFindings(array $findings): array
    {
        $distinct = [];
        foreach ($findings as $finding) {
            $distinct[json_encode([$finding->file, $finding->line, $finding->severity->value], JSON_THROW_ON_ERROR)] = $finding;
        }

        return array_values($distinct);
    }

    /**
     * @param list<Finding> $findings
     *
     * @return list<Finding>
     */
    private function distinctIntaktFindings(array $findings): array
    {
        // Several reviewers raising the same spot are one finding; one without a file or line has no spot to compare.
        $distinct = [];
        foreach ($findings as $index => $finding) {
            $spot = null === $finding->file || null === $finding->line ? $index : json_encode([$finding->file, $finding->line, $finding->severity->value], JSON_THROW_ON_ERROR);
            $distinct[$spot] = $finding;
        }

        return array_values($distinct);
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
