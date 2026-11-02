<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

use Intakt\Core\Review\Contract\Finding;

// Counts and findings only, because a limit or reset time in a comparison would leak account state into the report.
final readonly class HeadComparison
{
    /**
     * @param list<ReviewerDecision> $loopDecisions
     * @param list<ReviewerDecision> $intaktDecisions
     * @param list<FindingMatch>     $matches
     * @param list<LoopFinding>      $loopOnly
     * @param list<Finding>          $intaktOnly
     */
    public function __construct(
        public string $head,
        public array $loopDecisions,
        public array $intaktDecisions,
        public array $matches,
        public array $loopOnly,
        public array $intaktOnly,
        public int $intaktTokens,
    ) {
    }

    public function severityDisagreements(): int
    {
        return count(array_filter($this->matches, static fn (FindingMatch $match): bool => $match->disagreesOnSeverity()));
    }

    public function intaktFailedReviews(): int
    {
        return $this->countOutcome($this->intaktDecisions, ReviewOutcome::Failed);
    }

    public function unreadableLoopReviews(): int
    {
        return $this->countOutcome($this->loopDecisions, ReviewOutcome::Unreadable);
    }

    public function intaktReviews(): int
    {
        return count($this->intaktDecisions);
    }

    /**
     * @param list<ReviewerDecision> $decisions
     */
    private function countOutcome(array $decisions, ReviewOutcome $outcome): int
    {
        return count(array_filter($decisions, static fn (ReviewerDecision $decision): bool => $decision->outcome === $outcome));
    }
}
