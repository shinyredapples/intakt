<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Contract;

// Other modules link to a dashboard page by these names, so they never spell the route twice.
final readonly class DashboardRoutes
{
    public const string PREFIX = 'dashboard_';
    public const string SHOW_BOARD = self::PREFIX.'show_board';
    public const string RUN_SETUP = self::PREFIX.'run_setup';
    public const string LIST_PROJECTS = self::PREFIX.'list_projects';
    public const string LIST_REVIEWS = self::PREFIX.'list_reviews';
    public const string LIST_WAITING = self::PREFIX.'list_waiting';
    public const string SHOW_TRENDS = self::PREFIX.'show_trends';
    public const string SEARCH = self::PREFIX.'search';
    public const string SHOW_COMPONENTS = self::PREFIX.'show_components';
    public const string SHOW_REVIEW = self::PREFIX.'show_review';
    public const string SHOW_REVIEW_RECORD = self::PREFIX.'show_review_record';
    public const string START_REVIEW = self::PREFIX.'start_review';
    public const string START_FIRST_REVIEW = self::PREFIX.'start_first_review';
    public const string KEEP_PROVIDER_KEY = self::PREFIX.'keep_provider_key';
    public const string STOP_REVIEW = self::PREFIX.'stop_review';
    public const string STOP_ROUND = self::PREFIX.'stop_round';
    public const string SHOW_TASK = self::PREFIX.'show_task';
    public const string SHOW_DEMO = self::PREFIX.'show_demo';
    public const string PLAY_DEMO = self::PREFIX.'play_demo';
    public const string SHOW_STATUS = self::PREFIX.'show_status';
    public const string SHOW_LANES = self::PREFIX.'show_lanes';
}
