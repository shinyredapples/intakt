<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\ReviewBrief;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Contract\VerdictUnreadable;
use Intakt\Core\Review\Domain\GoalpostRule;

// Plain code checks the ids, because only the request knows which findings were open; an id the reviewer invented would otherwise close or revive a finding that never existed. One home for the reviewer in the app's own process and the one in a review job.
final readonly class OpenFindingIds
{
    // A capital letter and a short number, because FindingLedger numbers its findings that way and such an id is too short to hold a key.
    private const string LEDGER_ID = '/^[A-Z]\d{1,9}$/';

    // Refused like unreadable output, because a counted vote under a reserved id would send the head back with findings the ledger drops; key-safe, because only Intakt's own ids are named.
    /**
     * @throws VerdictUnreadable
     */
    public function refuseClaimedFailureIds(Verdict $verdict): void
    {
        $claimed = [...$verdict->claimedFailureIds()];

        if ([] !== $claimed) {
            throw VerdictUnreadable::becauseKeySafe(sprintf('the verdict names finding ids reserved for failed reviews: %s.', implode(', ', $claimed)));
        }
    }

    // Lowered here as the round lowers it before storing, because the cut-input guard and the vendor's read guard refuse what can count as approval, and a P2 the goalpost rule turns into a P3 can.
    public function asTheRoundCounts(ReviewBrief $brief, Verdict $verdict): Verdict
    {
        return new GoalpostRule()->applyToVerdict($brief->round, $verdict, $brief->open);
    }

    /**
     * @throws VerdictUnreadable
     */
    public function checkedAgainst(ReviewBrief $brief, Verdict $verdict): Verdict
    {
        $open = array_map(static fn (Finding $finding): string => $finding->id, $brief->open);
        $repeated = array_map(static fn (Finding $finding): string => $finding->id, array_filter($verdict->findings, static fn (Finding $finding): bool => $finding->repeat));
        $resolved = array_map(static fn (ResolvedFinding $resolved): string => $resolved->id, $verdict->resolved);
        $unknown = array_unique([...array_diff($resolved, $open), ...array_diff($repeated, $open)]);

        if ([] !== $unknown) {
            throw $this->unknownIds($unknown);
        }

        // Refused, because the ledger cannot tell which of the two the reviewer meant; key-safe, because every id named here is an open one the ledger gave.
        $contradicted = array_unique(array_intersect($resolved, $repeated));

        return [] === $contradicted ? $verdict : throw VerdictUnreadable::becauseKeySafe(sprintf('the verdict marks ids both resolved and repeat: %s.', implode(', ', $contradicted)));
    }

    // The ids are the model's own text, so they are recorded only when each has the short shape of a ledger id, which leaves no room for a key.
    /**
     * @param array<string> $unknown
     */
    private function unknownIds(array $unknown): VerdictUnreadable
    {
        $reason = sprintf('the verdict names ids that were not open: %s.', implode(', ', $unknown));

        return [] === preg_grep(self::LEDGER_ID, $unknown, PREG_GREP_INVERT) ? VerdictUnreadable::becauseKeySafe($reason) : VerdictUnreadable::because($reason);
    }
}
