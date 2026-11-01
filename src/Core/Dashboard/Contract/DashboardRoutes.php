<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Contract;

// Other modules link to a dashboard page by these names, so they never spell the route twice.
final readonly class DashboardRoutes
{
    public const string PREFIX = 'dashboard_';
    public const string LIST_PROJECTS = self::PREFIX.'list_projects';
    public const string LIST_REVIEWS = self::PREFIX.'list_reviews';
}
