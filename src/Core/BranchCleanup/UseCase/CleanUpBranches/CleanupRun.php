<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\UseCase\CleanUpBranches;

use DateTimeImmutable;
use Intakt\Core\BranchCleanup\Domain\BranchFacts;
use Intakt\Core\BranchCleanup\Domain\BranchRules;
use Intakt\Core\BranchCleanup\Domain\BranchWarning;
use Intakt\Core\BranchCleanup\Domain\Verdict;
use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\BranchArchive;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchNotArchived;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeBranches;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One project's run at one moment, so every branch is judged against the same rules, warnings and base.
#[Exclude]
final readonly class CleanupRun
{
    private const string WARNING_AHEAD = '+7 days';

    /**
     * @param array<string, BranchWarning> $stored
     */
    public function __construct(
        private string $project,
        private ForgeBranches $forge,
        private BranchArchive $archive,
        private BranchRules $rules,
        private array $stored,
        private string $base,
        private DateTimeImmutable $now,
    ) {
    }

    /**
     * @return list<ArchivedBranch|BranchWarning|BranchHead|string>
     */
    public function outcomesOf(ForgeBranch $branch): array
    {
        $head = new BranchHead($branch->name, $branch->head->hash);
        $facts = new BranchFacts($branch, $this->forge->changeOf($this->project, $branch), $this->archive->headOf($this->project, $head, $this->base));
        $warning = $this->warningOf($head);
        $failures = [];

        if (Verdict::Due === $this->rules->verdictOf($facts, $this->now) && true === $warning?->allowsDeletionAt($this->now)) {
            try {
                return [$this->delete($branch, $head)];
            } catch (BranchNotArchived|ForgeWriteFailed|ForgeRateLimited $failed) {
                $failures[] = sprintf('%s: %s', $branch->name, $failed->getMessage());
            }
        }

        return [...$failures, ...$this->noticeOf($facts, $warning ?? new BranchWarning($this->project, $branch->name, $branch->head->hash, $this->now), $head)];
    }

    private function warningOf(BranchHead $head): ?BranchWarning
    {
        $warning = $this->stored[$head->branch] ?? null;

        return true === $warning?->names($head) ? $warning : null;
    }

    // Archived before the forge call, so a head is never deleted without its archive ref.
    private function delete(ForgeBranch $branch, BranchHead $head): ArchivedBranch
    {
        $archived = $this->archive->archive($this->project, $head);
        $this->forge->deleteBranch($this->project, $branch);

        return $archived;
    }

    /**
     * @return list<BranchWarning|BranchHead>
     */
    private function noticeOf(BranchFacts $facts, BranchWarning $warning, BranchHead $head): array
    {
        return match (true) {
            Verdict::Due === $this->rules->verdictOf($facts, $this->now->modify(self::WARNING_AHEAD)) => [$warning],
            Verdict::Stale === $this->rules->verdictOf($facts, $this->now) => [$head],
            default => [],
        };
    }
}
