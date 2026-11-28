<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\ResolvedFinding;
use Intakt\Core\Review\Contract\Verdict;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A value built per change, not a service, so the container must not wire it.
#[Exclude]
final class FindingLedger
{
    /** @var array<string, TrackedFinding> */
    private array $tracked;

    private ?int $latestRound = null;

    private readonly GoalpostRule $goalposts;

    /**
     * @param list<TrackedFinding> $tracked
     */
    public function __construct(array $tracked = [], private readonly string $idPrefix = 'F')
    {
        $this->tracked = array_column($tracked, null, 'id');
        $this->goalposts = new GoalpostRule();
    }

    // Ids under the round's own prefix, for a ledger that is never stored, so they cannot meet the F<n> ids the stored ledger hands out later.
    /**
     * @param list<TrackedFinding> $tracked
     */
    public static function apartFor(int $round, array $tracked): self
    {
        return new self($tracked, 'R'.$round.'-F');
    }

    /**
     * @param list<Finding> $findings
     */
    public function recordRound(int $round, array $findings): void
    {
        $this->advanceTo($round);
        $earlier = $this->tracked;
        foreach ($findings as $finding) {
            $this->track($round, $finding, $earlier);
        }
    }

    // Unlike recordRound, only a finding marked repeat keeps an id: the reviewer was shown the open ids and says which ones came back. Returns the ledger ids the verdict's findings were tracked under, in its order, so the round can record which reviewer reported which finding.
    /**
     * @return list<string>
     */
    public function recordVerdict(int $round, Verdict $verdict): array
    {
        // A failed review is a fault of the reviewer, not of the change, so it adds nothing, the same as a reviewer that failed or was blocked.
        if ($verdict->isFailedReview()) {
            return [];
        }

        // A finding another reviewer already reported again in this round stays open, so the verdicts of one round count the same in any order.
        $this->resolve(array_values(array_map(static fn (ResolvedFinding $resolved): string => $resolved->id, array_filter($verdict->resolved, fn (ResolvedFinding $resolved): bool => ($this->tracked[$resolved->id] ?? null)?->latestRound() !== $round))));
        $earlier = $this->tracked;

        return array_map(fn (Finding $finding): string => $this->trackRepeat($round, $finding, $earlier), $verdict->findings);
    }

    /**
     * @return list<Finding>
     */
    public function openForReviewers(): array
    {
        return array_map($this->underItsId(...), $this->open());
    }

    // The open findings a round reported last, under the ledger's ids, because two reviewers can give different findings the same id of their own.
    /**
     * @return list<Finding>
     */
    public function openReportedIn(int $round): array
    {
        $reported = array_filter($this->open(), static fn (TrackedFinding $tracked): bool => $tracked->latestRound() === $round);

        return array_values(array_map($this->underItsId(...), $reported));
    }

    /**
     * @param list<string> $ids
     */
    public function resolve(array $ids): void
    {
        foreach ($ids as $id) {
            if (isset($this->tracked[$id])) {
                $this->tracked[$id] = $this->tracked[$id]->resolved();
            }
        }
    }

    /**
     * @return list<TrackedFinding>
     */
    public function open(): array
    {
        return array_values(array_filter($this->tracked, static fn (TrackedFinding $tracked): bool => $tracked->isOpen()));
    }

    /**
     * @return list<TrackedFinding>
     */
    public function all(): array
    {
        return array_values($this->tracked);
    }

    // A repeat in an earlier or the same round would land out of order in a finding's rounds or not reopen it.
    private function advanceTo(int $round): void
    {
        if (null !== $this->latestRound && $round <= $this->latestRound) {
            throw RoundNotAfterLatest::for($round, $this->latestRound);
        }

        $this->latestRound = $round;
    }

    /**
     * @param array<string, TrackedFinding> $earlier
     */
    private function track(int $round, Finding $finding, array $earlier): void
    {
        // Only ids issued before this round are repeats: a reviewer cannot know an id issued in the same round.
        if (!isset($earlier[$finding->id])) {
            $this->start($round, $finding);

            return;
        }

        $this->tracked[$finding->id] = $this->tracked[$finding->id]->seenAgain($round, $finding);
    }

    /**
     * @param array<string, TrackedFinding> $earlier
     */
    private function trackRepeat(int $round, Finding $finding, array $earlier): string
    {
        if ($finding->repeat && isset($earlier[$finding->id])) {
            $this->tracked[$finding->id] = $this->tracked[$finding->id]->seenAgain($round, $finding);

            return $finding->id;
        }

        return $this->start($round, $this->goalposts->applyTo($round, $finding));
    }

    private function underItsId(TrackedFinding $tracked): Finding
    {
        return new Finding(
            $tracked->id,
            $tracked->latest->severity,
            $tracked->latest->file,
            $tracked->latest->line,
            $tracked->latest->symbol,
            $tracked->latest->text,
            $tracked->latest->fixable_by,
            $tracked->latest->repeat,
            $tracked->latest->requirement,
            $tracked->latest->evidence,
            $tracked->latest->regression,
            $tracked->latest->gate_gap,
            $tracked->latest->title,
            $tracked->latest->kind,
        );
    }

    private function start(int $round, Finding $finding): string
    {
        $id = $this->idPrefix.(count($this->tracked) + 1);
        $this->tracked[$id] = new TrackedFinding($id, $finding, [$round], FindingStatus::Open);

        return $id;
    }
}
