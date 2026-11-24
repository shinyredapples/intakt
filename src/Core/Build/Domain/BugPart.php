<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Domain;

// In the order a bug task states them, because the question names a missing part in that order.
enum BugPart: string
{
    case When = 'When';
    case Today = 'Today';
    case Expected = 'Expected';
    case MustKeepWorking = 'Must keep working';
}
