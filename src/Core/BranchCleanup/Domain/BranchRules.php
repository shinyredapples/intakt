<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\BranchChangeState;
use Intakt\Plugin\Forge\Contract\ForgeBranch;

// The cleanup rules of docs/design/05-architecture/08-merge.md#branch-cleanup, each one step of a chain.
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

    /**
     * @param list<ForgeBranch> $branches
     *
     * @return list<ForgeBranch>
     */
    public function touchable(array $branches): array
    {
        return $branches
            |> $this->withIntaktsPrefixUnlessOptedIn(...)
            |> $this->withoutTheDefault(...)
            |> $this->withoutProtected(...)
            |> $this->withoutReleases(...);
    }

    public function verdictOf(BranchFacts $facts, DateTimeImmutable $at): Verdict
    {
        return new Judgement($facts, $at)
            |> $this->keptWhenTheHeadIsUnknown(...)
            |> $this->dueWhenMerged(...)
            |> $this->dueWhenClosedLongAgo(...)
            |> $this->keptWithAnOpenChange(...)
            |> $this->keptWhenClaimed(...)
            |> $this->dueWhenUnclaimedLongAgo(...)
            |> $this->staleWithoutRecentCommits(...)
            |> $this->keptUnlessDecided(...);
    }

    /**
     * @param list<ForgeBranch> $branches
     *
     * @return list<ForgeBranch>
     */
    private function withIntaktsPrefixUnlessOptedIn(array $branches): array
    {
        return array_values(array_filter($branches, fn (ForgeBranch $branch): bool => $this->policy->mayTouch($branch->name)));
    }

    /**
     * @param list<ForgeBranch> $branches
     *
     * @return list<ForgeBranch>
     */
    private function withoutTheDefault(array $branches): array
    {
        return array_values(array_filter($branches, static fn (ForgeBranch $branch): bool => !$branch->isDefault));
    }

    /**
     * @param list<ForgeBranch> $branches
     *
     * @return list<ForgeBranch>
     */
    private function withoutProtected(array $branches): array
    {
        return array_values(array_filter($branches, static fn (ForgeBranch $branch): bool => !$branch->isProtected));
    }

    /**
     * @param list<ForgeBranch> $branches
     *
     * @return list<ForgeBranch>
     */
    private function withoutReleases(array $branches): array
    {
        return array_values(array_filter($branches, fn (ForgeBranch $branch): bool => !$this->policy->isRelease($branch->name)));
    }

    private function keptWhenTheHeadIsUnknown(Judgement $judgement): Judgement
    {
        return null === $judgement->facts->head ? $judgement->decided(Verdict::Keep) : $judgement;
    }

    private function dueWhenMerged(Judgement $judgement): Judgement
    {
        return BranchChangeState::Merged === $judgement->facts->change?->state ? $judgement->decided(Verdict::Due) : $judgement;
    }

    private function dueWhenClosedLongAgo(Judgement $judgement): Judgement
    {
        $change = $judgement->facts->change;
        if (BranchChangeState::ClosedUnmerged !== $change?->state) {
            return $judgement;
        }

        $due = !in_array(self::KEEP_LABEL, $change->labels, true) && null !== $change->closedAt && $judgement->isPast($change->closedAt, self::CLOSED_DAYS);

        return $judgement->decided($due ? Verdict::Due : Verdict::Keep);
    }

    private function keptWithAnOpenChange(Judgement $judgement): Judgement
    {
        return null !== $judgement->facts->change ? $judgement->decided(Verdict::Keep) : $judgement;
    }

    // A claimed branch may still be listed stale, but never becomes due.
    private function keptWhenClaimed(Judgement $judgement): Judgement
    {
        $claimed = true === $judgement->facts->head?->claimedByPerson || $this->policy->keepsByName($judgement->facts->branch->name);

        return $claimed ? $judgement->decided($this->isStale($judgement) ? Verdict::Stale : Verdict::Keep) : $judgement;
    }

    private function dueWhenUnclaimedLongAgo(Judgement $judgement): Judgement
    {
        return $this->isOlderThan($judgement, self::UNCLAIMED_DAYS) ? $judgement->decided(Verdict::Due) : $judgement;
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
        return $this->isOlderThan($judgement, self::STALE_DAYS);
    }

    private function isOlderThan(Judgement $judgement, int $days): bool
    {
        $head = $judgement->facts->head;

        return null !== $head && $judgement->isPast($head->committedAt, $days);
    }
}
