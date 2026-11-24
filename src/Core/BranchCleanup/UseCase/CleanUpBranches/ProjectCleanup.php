<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\UseCase\CleanUpBranches;

use DateTimeImmutable;
use Intakt\Core\BranchCleanup\Domain\BranchFacts;
use Intakt\Core\BranchCleanup\Domain\BranchRules;
use Intakt\Core\BranchCleanup\Domain\BranchWarning;
use Intakt\Core\BranchCleanup\Domain\Verdict;
use Intakt\Core\BranchCleanup\Infrastructure\CleanupPolicies;
use Intakt\Core\BranchCleanup\Infrastructure\ProjectForges;
use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\BranchArchive;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Core\Git\Contract\BranchNotArchived;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Intakt\Plugin\Forge\Contract\ForgeBranches;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

// Warns a week ahead and deletes only what a warning of at least a week named at the same head, so nothing goes without notice.
final readonly class ProjectCleanup
{
    private const string WARNING_AHEAD = '+7 days';

    public function __construct(
        private ProjectForges $projects,
        private CleanupPolicies $policies,
        private BranchArchive $archive,
        private BranchWarnings $warnings,
        private ClockInterface $clock,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    public function run(string $project): CleanupReport
    {
        // Tried without waiting, because the weekly run and a run by hand must not both judge the same warnings.
        $lock = $this->locks->createLock('branch-cleanup-'.$project);
        if (!$lock->acquire()) {
            throw CleanupNotRun::alreadyRunning($project);
        }

        try {
            $report = $this->cleanUp($project);
        } finally {
            $lock->release();
        }

        $this->logger->notice('Branch cleanup of {project}: {warned} branches warned, {deleted} deleted, {stale} stale.', ['project' => $project, 'warned' => count($report->warned), 'deleted' => count($report->deleted), 'stale' => count($report->stale)]);

        return $report;
    }

    private function cleanUp(string $project): CleanupReport
    {
        $forge = $this->projects->forgeOf($project);
        $listed = $forge->branchesOf($project);
        $base = array_first(array_filter($listed, static fn (ForgeBranch $branch): bool => $branch->isDefault)) ?? throw CleanupNotRun::withoutDefaultBranch($project);
        $this->archive->refresh($project, $this->projects->repositoryOf($project));

        $rules = new BranchRules($this->policies->of($project));
        $stored = $this->warnings->of($project);
        $now = $this->clock->now();
        [$warned, $deleted, $stale, $failures] = [[], [], [], []];

        foreach ($rules->touchable($listed) as $branch) {
            $head = new BranchHead($branch->name, $branch->head->hash);
            $facts = new BranchFacts($branch, $forge->changeOf($project, $branch), $this->archive->headOf($project, $head, $base->head->hash));
            $warning = $this->warningOf($stored, $head);

            if (Verdict::Due === $rules->verdictOf($facts, $now) && true === $warning?->allowsDeletionAt($now)) {
                try {
                    $deleted[] = $this->delete($forge, $project, $branch);

                    continue;
                } catch (BranchNotArchived|ForgeWriteFailed|ForgeRateLimited $failed) {
                    $failures[] = sprintf('%s: %s', $branch->name, $failed->getMessage());
                }
            }

            if (Verdict::Due === $rules->verdictOf($facts, $this->aWeekAfter($now))) {
                $warned[] = $warning ?? new BranchWarning($project, $branch->name, $branch->head->hash, $now);

                continue;
            }

            if (Verdict::Stale === $rules->verdictOf($facts, $now)) {
                $stale[] = $head;
            }
        }

        $this->warnings->replace($project, $warned);

        return new CleanupReport($project, $warned, $deleted, $stale, $failures);
    }

    /**
     * @param array<string, BranchWarning> $stored
     */
    private function warningOf(array $stored, BranchHead $head): ?BranchWarning
    {
        $warning = $stored[$head->branch] ?? null;

        return true === $warning?->names($head) ? $warning : null;
    }

    // Archived before the forge call, so a head is never deleted without its archive ref.
    private function delete(ForgeBranches $forge, string $project, ForgeBranch $branch): ArchivedBranch
    {
        $archived = $this->archive->archive($project, new BranchHead($branch->name, $branch->head->hash));
        $forge->deleteBranch($project, $branch);

        return $archived;
    }

    private function aWeekAfter(DateTimeImmutable $now): DateTimeImmutable
    {
        return $now->modify(self::WARNING_AHEAD);
    }
}
