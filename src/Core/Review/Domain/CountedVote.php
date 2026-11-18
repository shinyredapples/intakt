<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The vote a reviewer's verdict counts as for the quorum, so a round that ends as changes always names what to change (issue #1860, D4); a value built per round, not a service.
#[Exclude]
final readonly class CountedVote
{
    // The reported findings are under the ledger's ids, because the act ids name those.
    /**
     * @param list<Finding> $reported
     * @param list<string>  $actIds
     */
    public function __construct(
        private array $reported,
        private array $actIds,
    ) {
    }

    // Only a changes verdict of P3 findings the judge left off the act list counts as approval, because its findings then reach the builder as follow-ups and never as a fix.
    public function of(Verdict $verdict): Verdict
    {
        if (Decision::Changes !== $verdict->verdict || $verdict->isFailedReview() || !array_all($verdict->findings, $this->isFollowUp(...))) {
            return $verdict;
        }

        return new Verdict(Decision::Approve, [], $verdict->resolved, [...$verdict->follow_ups, ...array_map(static fn (Finding $finding): string => $finding->id, $verdict->findings)]);
    }

    // A finding no reported one matches keeps the vote as changes, because a vote is never approved on a guess.
    private function isFollowUp(Finding $finding): bool
    {
        $matches = array_filter($this->reported, static fn (Finding $reported): bool => self::isReportedAs($finding, $reported));

        return Severity::P3 === $finding->severity
            && [] !== $matches
            && !array_any($matches, fn (Finding $reported): bool => in_array($reported->id, $this->actIds, true));
    }

    // By id for a repeat and by content for a new finding, because the ledger gives a new finding an id of its own and keeps its fields; a match by either keeps every possible act mark in view.
    private static function isReportedAs(Finding $finding, Finding $reported): bool
    {
        return ($finding->repeat && $finding->id === $reported->id)
            || ($finding->severity === $reported->severity
                && $finding->file === $reported->file
                && $finding->line === $reported->line
                && $finding->symbol === $reported->symbol
                && $finding->text === $reported->text);
    }
}
