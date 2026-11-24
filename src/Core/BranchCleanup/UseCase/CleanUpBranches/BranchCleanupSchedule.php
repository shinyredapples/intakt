<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\UseCase\CleanUpBranches;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

// Run by `messenger:consume scheduler_branch_cleanup`, beside the watcher's consumer. Stateful, because that consumer restarts every hour and would otherwise start the week afresh each time, so a weekly run would never come; a run missed while it was down comes once at its next start.
#[AsSchedule('branch_cleanup')]
final readonly class BranchCleanupSchedule implements ScheduleProviderInterface
{
    public const string INTERVAL = '1 week';

    // A Monday early in the morning (UTC), so every run falls on the same quiet moment of the week.
    public const string FIRST_RUN = '2026-11-23 03:00:00 UTC';

    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->add(RecurringMessage::every(self::INTERVAL, new CleanUpBranches(), self::FIRST_RUN))
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true);
    }
}
