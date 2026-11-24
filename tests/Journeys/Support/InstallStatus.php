<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

enum InstallStatus: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
