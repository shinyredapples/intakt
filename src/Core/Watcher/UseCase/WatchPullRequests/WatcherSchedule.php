<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\UseCase\WatchPullRequests;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

// Run by `messenger:consume scheduler_watcher`, because the tick is the only trigger until webhooks (plan task T32) and stays the fallback after.
#[AsSchedule('watcher')]
final readonly class WatcherSchedule implements ScheduleProviderInterface
{
    public const string INTERVAL = '1 minute';

    public function __construct(private WatchedProjects $projects)
    {
    }

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();

        foreach ($this->projects->names() as $project) {
            $schedule->add(RecurringMessage::every(self::INTERVAL, new WatchPullRequests($project)));
        }

        return $schedule;
    }
}
