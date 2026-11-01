<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use Throwable;

// An expected failure whose message is written for the person running the command, so a command prints it while anything else still crashes.
interface ReportableFailure extends Throwable
{
}
