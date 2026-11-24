<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\UseCase\CleanUpBranches;

use Intakt\Core\BranchCleanup\Domain\BranchRules;
use Intakt\Core\BranchCleanup\Domain\BranchWarning;
use Intakt\Core\BranchCleanup\Infrastructure\CleanupPolicies;
use Intakt\Core\BranchCleanup\Infrastructure\ProjectForges;
use Intakt\Core\Git\Contract\ArchivedBranch;
use Intakt\Core\Git\Contract\BranchArchive;
use Intakt\Core\Git\Contract\BranchHead;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

// Warns a week ahead and deletes only what a warning of at least a week named at the same head, so nothing goes without notice.
final readonly class ProjectCleanup
{
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

        $this->logger->notice('Branch cleanup of {project}: {warned} branches warned ({branches}), {deleted} deleted, {stale} stale.', ['project' => $project, 'warned' => count($report->warned), 'branches' => $this->warningList($report), 'deleted' => count($report->deleted), 'stale' => count($report->stale)]);

        return $report;
    }

    private function cleanUp(string $project): CleanupReport
    {
        $forge = $this->projects->forgeOf($project);
        $listed = $forge->branchesOf($project);
        $base = array_first(array_filter($listed, static fn (ForgeBranch $branch): bool => $branch->isDefault)) ?? throw CleanupNotRun::withoutDefaultBranch($project);
        $this->archive->refresh($project, $this->projects->repositoryOf($project));

        $rules = new BranchRules($this->policies->of($project));
        $run = new CleanupRun($project, $forge, $this->archive, $rules, $this->warnings->of($project), $base->head->hash, $this->clock->now());
        $outcomes = [];

        foreach ($rules->touchable($listed) as $branch) {
            array_push($outcomes, ...$run->outcomesOf($branch));
        }

        $warned = array_values(array_filter($outcomes, static fn (mixed $outcome): bool => $outcome instanceof BranchWarning));
        $this->warnings->replace($project, $warned);

        return new CleanupReport(
            $project,
            $warned,
            array_values(array_filter($outcomes, static fn (mixed $outcome): bool => $outcome instanceof ArchivedBranch)),
            array_values(array_filter($outcomes, static fn (mixed $outcome): bool => $outcome instanceof BranchHead)),
            array_values(array_filter($outcomes, is_string(...))),
        );
    }

    // The names in the notice itself, because the scheduled run has no console that would print the list.
    private function warningList(CleanupReport $report): string
    {
        return implode(', ', array_map(static fn (BranchWarning $warning): string => sprintf('%s deletable from %s', $warning->branch(), $warning->deletableFrom()->format('Y-m-d')), $report->warned));
    }
}
