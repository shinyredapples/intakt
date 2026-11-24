<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ExpireConversations;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

// Run by `messenger:consume scheduler_retention`, beside the watcher's consumer; hourly because a day is the finest retention the setting offers.
#[AsSchedule('retention')]
final readonly class RetentionSchedule implements ScheduleProviderInterface
{
    public const string INTERVAL = '1 hour';

    public function getSchedule(): Schedule
    {
        return new Schedule()->add(RecurringMessage::every(self::INTERVAL, new ExpireConversations()));
    }
}
