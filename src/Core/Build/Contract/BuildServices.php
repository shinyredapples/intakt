<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

// The container parameter names of this module, so config and code never spell one twice.
final readonly class BuildServices
{
    public const string ROUTING = 'intakt.build.routing';

    public const string SLOTS = 'intakt.build.slots';

    public const string TOOL_TIME_LIMIT = 'intakt.build.tool_time_limit_seconds';

    public const string ALLOWANCE_TOKENS = 'intakt.build.allowance_tokens';

    public const string IDLE_SECONDS = 'intakt.build.idle_seconds';

    public const string CEILING_TOKENS = 'intakt.build.ceiling_tokens';

    public const string TASK_TOTAL_TOKENS = 'intakt.build.task_total_tokens';
}
