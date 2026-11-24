<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ExpireConversations;

use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

// Run by `messenger:consume scheduler_retention`, beside the watcher's consumer. Well inside the consumer's hour-long life, because each start of the consumer starts the schedule afresh, so a run due only after an hour would never come.
#[AsSchedule('retention')]
final readonly class RetentionSchedule implements ScheduleProviderInterface
{
    public const string INTERVAL = '10 minutes';

    public function getSchedule(): Schedule
    {
        return new Schedule()->add(RecurringMessage::every(self::INTERVAL, new ExpireConversations()));
    }
}
