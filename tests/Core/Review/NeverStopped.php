<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review;

use Intakt\Core\Review\Infrastructure\ReviewerStopping;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

// For the tests that run a reviewer and press no stop button: its ledger holds no request, so nothing is cancelled.
final readonly class NeverStopped
{
    public static function by(): ReviewerStopping
    {
        return new ReviewerStopping(new InMemoryLedger(), new RecordedRoundUpdates(), new MockClock(), new NullLogger());
    }
}
