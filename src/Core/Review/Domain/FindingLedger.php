<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\Verdict;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A value built per change, not a service, so the container must not wire it.
#[Exclude]
final class FindingLedger
{
    /** @var array<string, TrackedFinding> */
    private array $tracked;

    private ?int $latestRound = null;

    /**
     * @param list<TrackedFinding> $tracked
     */
    public function __construct(array $tracked = [])
    {
        $this->tracked = array_column($tracked, null, 'id');
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

    // Unlike recordRound, only a finding marked repeat keeps an id: the reviewer was shown the open ids and says which ones came back.
    public function recordVerdict(int $round, Verdict $verdict): void
    {
        // A failed review is a fault of the reviewer, not of the change, so it adds nothing, the same as a reviewer that failed or was blocked.
        if ($verdict->isFailedReview()) {
            return;
        }

        // A finding another reviewer already reported again in this round stays open, so the verdicts of one round count the same in any order.
        $this->resolve(array_values(array_filter($verdict->resolved, fn (string $id): bool => ($this->tracked[$id] ?? null)?->latestRound() !== $round)));
        $earlier = $this->tracked;
        foreach ($verdict->findings as $finding) {
            $this->trackRepeat($round, $finding, $earlier);
        }
    }

    /**
     * @return list<Finding>
     */
    public function openForReviewers(): array
    {
        return array_map(
            static fn (TrackedFinding $tracked): Finding => new Finding(
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
            ),
            $this->open(),
        );
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
    private function trackRepeat(int $round, Finding $finding, array $earlier): void
    {
        if ($finding->repeat && isset($earlier[$finding->id])) {
            $this->tracked[$finding->id] = $this->tracked[$finding->id]->seenAgain($round, $finding);

            return;
        }

        $this->start($round, $finding);
    }

    private function start(int $round, Finding $finding): void
    {
        $id = 'F'.(count($this->tracked) + 1);
        $this->tracked[$id] = new TrackedFinding($id, $finding, [$round], FindingStatus::Open);
    }
}
