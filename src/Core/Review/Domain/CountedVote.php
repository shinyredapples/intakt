<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\JudgedFindings;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The vote a reviewer's verdict counts as for the quorum, so a round that ends as changes always names what to change (issue #1860, D4); a value built per round, not a service.
#[Exclude]
final readonly class CountedVote
{
    /** @var list<string> */
    private array $judgedIds;

    /** @var list<string> */
    private array $actIds;

    // The reported findings are under the ledger's ids, because the judge's lists name those; the shown ids are the open ones the round's reviewers were given, the only ids a repeat can name.
    /**
     * @param list<Finding> $reported
     * @param list<string>  $shownIds
     */
    public function __construct(
        private array $reported,
        private array $shownIds,
        JudgedFindings $judged,
    ) {
        $this->judgedIds = array_map(static fn (Finding $finding): string => $finding->id, $judged->findings);
        $this->actIds = array_map(static fn (Finding $finding): string => $finding->id, $judged->actList);
    }

    // A changes verdict counts as changes only with a finding on the act list, as approval when the judge deferred or rejected every one of its findings and all are P3, and otherwise as blocked, because the builder would be sent back with nothing to act on.
    public function of(Verdict $verdict): Decision
    {
        if (Decision::Changes !== $verdict->verdict || $verdict->isFailedReview() || [] === $verdict->findings) {
            return $verdict->verdict;
        }

        $matches = array_map($this->reportedAs(...), $verdict->findings);
        $all = array_merge([], ...$matches);

        return match (true) {
            array_any($all, fn (Finding $reported): bool => in_array($reported->id, $this->actIds, true)) => Decision::Changes,
            array_all($matches, $this->isJudgedFollowUp(...)) => Decision::Approve,
            default => Decision::Blocked,
        };
    }

    // From the ledger's finding, because its severity is the one the judge and the act list go by; a finding the quote check dropped was never judged.
    /**
     * @param list<Finding> $matches
     */
    private function isJudgedFollowUp(array $matches): bool
    {
        return [] !== $matches && array_all($matches, fn (Finding $reported): bool => Severity::P3 === $reported->severity && in_array($reported->id, $this->judgedIds, true));
    }

    // A repeat of a shown id by that id alone, because the ledger keeps it under that id; a new finding by its place and text, because the ledger gives it an id of its own and keeps them, and every match stays in view so no act mark is missed.
    /**
     * @return list<Finding>
     */
    private function reportedAs(Finding $finding): array
    {
        if ($finding->repeat && in_array($finding->id, $this->shownIds, true)) {
            return array_values(array_filter($this->reported, static fn (Finding $reported): bool => $finding->id === $reported->id));
        }

        return array_values(array_filter($this->reported, static fn (Finding $reported): bool => $finding->file === $reported->file
            && $finding->line === $reported->line
            && $finding->symbol === $reported->symbol
            && $finding->text === $reported->text));
    }
}
