<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Contract;

// Other modules link to a dashboard page by these names, so they never spell the route twice.
final readonly class DashboardRoutes
{
    public const string PREFIX = 'dashboard_';
    public const string SHOW_BOARD = self::PREFIX.'show_board';
    public const string LIST_PROJECTS = self::PREFIX.'list_projects';
    public const string LIST_REVIEWS = self::PREFIX.'list_reviews';
    public const string SEARCH = self::PREFIX.'search';
    public const string SHOW_COMPONENTS = self::PREFIX.'show_components';
    public const string SHOW_REVIEW = self::PREFIX.'show_review';
    public const string START_REVIEW = self::PREFIX.'start_review';
    public const string STOP_REVIEW = self::PREFIX.'stop_review';
}
