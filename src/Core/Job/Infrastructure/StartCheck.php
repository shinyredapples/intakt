<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

// Spelled as docker/job/start-check.sh writes them, because its report is read by these names.
enum StartCheck: string
{
    case Output = 'output';
    case Work = 'work';
    case Input = 'input';
    case Egress = 'egress';
    case Report = 'report';
}
