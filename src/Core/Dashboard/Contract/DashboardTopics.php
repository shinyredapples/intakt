<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Contract;

final class DashboardTopics
{
    // Listened to by every dashboard page, so an event for the whole shell (the project filter, the Waiting on you count) has one topic to be published on.
    public const string SHELL = 'dashboard/shell';

    // Listened to only by the History page, because every ledger event is published on it and no other page shows them.
    public const string HISTORY = 'dashboard/history';
}
