<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

// Spelled as docker/job/start-check.sh logs them, because its log lines are read by these names; Log is Intakt's own, for a log it cannot read.
enum StartCheck: string
{
    case Output = 'output';
    case Work = 'work';
    case Input = 'input';
    case Egress = 'egress';
    case Log = 'log';

    /**
     * @return list<self>
     */
    public static function logged(): array
    {
        return array_filter(self::cases(), static fn (self $check): bool => self::Log !== $check);
    }
}
