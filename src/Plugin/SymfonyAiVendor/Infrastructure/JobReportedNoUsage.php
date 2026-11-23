<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use RuntimeException;

// Recorded as the reason a job's call stays unsettled, because a job whose CLI reported no usage spent tokens all the same, and settling it as zero would hide them from the budget.
final class JobReportedNoUsage extends RuntimeException
{
    public static function inItsOutput(): self
    {
        return new self('The review job\'s CLI reported no usage.');
    }
}
