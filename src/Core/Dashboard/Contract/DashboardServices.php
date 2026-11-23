<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Contract;

// The parameter and service names of this module, so config and code never spell one twice.
final readonly class DashboardServices
{
    public const string BOARD_HEADLINES = 'intakt.dashboard.board_headlines';
    public const string SAMPLE_REPOSITORY = 'intakt.dashboard.sample_repository';
    public const string STATUS_PAGES = 'intakt.dashboard.status_pages';
}
