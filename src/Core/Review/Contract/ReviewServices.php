<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class ReviewServices
{
    public const string PANELS = 'intakt.review.panels';

    public const string MCP_URL = 'intakt.review.mcp_url';

    public const string JOB_IMAGES = 'intakt.review.job_images';

    public const string PROJECT_FILE = 'intakt.review.project_file';

    public const string CONVERSATION_DAYS = 'intakt.review.conversation_days';
}
