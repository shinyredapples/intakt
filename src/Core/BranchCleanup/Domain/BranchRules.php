<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\BranchChangeState;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Each rule of docs/design/05-architecture/08-merge.md#branch-cleanup is its own step, so the order in which they decide stays visible in one chain.
#[Exclude]
final readonly class BranchRules
{
    public const string KEEP_LABEL = 'keep';
    public const int CLOSED_DAYS = 30;
    public const int STALE_DAYS = 30;
    public const int UNCLAIMED_DAYS = 60;

    public function __construct(
        private CleanupPolicy $policy,
    ) {
    }

    public function verdictOf(BranchFacts $facts, DateTimeImmutable $now, ?BranchWarning $warning = null): Verdict
    {
        return new Judgement($facts, $now, $warning)
            |> $this->outOfBoundsWithoutIntaktsPrefix(...)
            |> $this->keptWhenTheForgeMayNotDeleteIt(...)
            |> $this->deletableWhenMerged(...)
            |> $this->dueWhenClosedLongAgo(...)
            |> $this->keptWithAnOpenChange(...)
            |> $this->keptWhenClaimed(...)
            |> $this->dueWhenUnclaimedLongAgo(...)
            |> $this->staleWithoutRecentCommits(...)
            |> $this->keptUnlessDecided(...);
    }

    private function outOfBoundsWithoutIntaktsPrefix(Judgement $judgement): Judgement
    {
        return $this->policy->mayTouch($judgement->facts->branch->name) ? $judgement : $judgement->decided(Verdict::OutOfBounds);
    }

    // The forge contract's own test (default, protected, release or symbolic), so the rules never call deletable what the forge refuses to delete.
    private function keptWhenTheForgeMayNotDeleteIt(Judgement $judgement): Judgement
    {
        return $judgement->facts->branch->isDeletable() ? $judgement : $judgement->decided(Verdict::Keep);
    }

    private function deletableWhenMerged(Judgement $judgement): Judgement
    {
        return BranchChangeState::Merged === $judgement->facts->change?->state ? $judgement->decided(Verdict::Deletable) : $judgement;
    }

    private function dueWhenClosedLongAgo(Judgement $judgement): Judgement
    {
        $change = $judgement->facts->change;
        if (BranchChangeState::ClosedUnmerged !== $change?->state) {
            return $judgement;
        }

        $closedAt = $judgement->facts->closedAt;
        if ($change->hasLabel(self::KEEP_LABEL) || !$closedAt instanceof DateTimeImmutable || !$judgement->isPast($closedAt, self::CLOSED_DAYS)) {
            return $judgement->decided(Verdict::Keep);
        }

        return $judgement->decidedDue();
    }

    private function keptWithAnOpenChange(Judgement $judgement): Judgement
    {
        return $judgement->facts->change instanceof BranchChange ? $judgement->decided(Verdict::Keep) : $judgement;
    }

    // Only a branch with no pull request reaches here, so keep-branches claims nothing else. A claimed branch may still be listed stale, but never becomes deletable.
    private function keptWhenClaimed(Judgement $judgement): Judgement
    {
        $claimed = false !== $judgement->facts->committedByAPerson || $this->policy->keepsByName($judgement->facts->branch->name);

        return $claimed ? $judgement->decided($this->isStale($judgement) ? Verdict::Stale : Verdict::Keep) : $judgement;
    }

    private function dueWhenUnclaimedLongAgo(Judgement $judgement): Judgement
    {
        return $judgement->isPast($judgement->facts->lastCommitAt, self::UNCLAIMED_DAYS) ? $judgement->decidedDue() : $judgement;
    }

    private function staleWithoutRecentCommits(Judgement $judgement): Judgement
    {
        return $this->isStale($judgement) ? $judgement->decided(Verdict::Stale) : $judgement;
    }

    private function keptUnlessDecided(Judgement $judgement): Verdict
    {
        return $judgement->verdict ?? Verdict::Keep;
    }

    private function isStale(Judgement $judgement): bool
    {
        return $judgement->isPast($judgement->facts->lastCommitAt, self::STALE_DAYS);
    }
}
