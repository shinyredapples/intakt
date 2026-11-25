<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// One home for the longest a reviewer may run, because the worker's hold and the dashboard's lanes both end with it.
final readonly class ReviewCeiling
{
    // One hour, because a redelivery inside the hold must wait for the live worker while one after it may treat the worker as stopped; the transport's redeliver_timeout stays above it so a redelivery never arrives inside the hold.
    public const int SECONDS = 3600;
}
