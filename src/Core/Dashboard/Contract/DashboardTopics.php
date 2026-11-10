<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Contract;

final class DashboardTopics
{
    // Listened to by every dashboard page, so an event for the whole shell (the project filter, the Waiting on you count) has one topic to be published on.
    public const string SHELL = 'dashboard/shell';
}
