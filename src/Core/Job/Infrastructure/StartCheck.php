<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

// The checks docker/job/start-check.sh runs, and the report it leaves, by the names the script writes.
enum StartCheck: string
{
    case Output = 'output';
    case Work = 'work';
    case Input = 'input';
    case Egress = 'egress';
    case Report = 'report';
}
