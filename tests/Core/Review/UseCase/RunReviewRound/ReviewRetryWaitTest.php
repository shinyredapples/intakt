<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Review\UseCase\RunReviewRound\ReviewerRateLimited;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Container\ContainerInterface;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

// The configured retry strategy of the async transport, because a busy account or a limit without a reset comes back after the wait it names.
#[CoversClass(ReviewerRateLimited::class)]
final class ReviewRetryWaitTest extends KernelTestCase
{
    // Within a tenth either way, because Messenger's jitter spreads the retries of workers that were refused together.
    public function testTheFirstRetryOfAMessageWithoutItsOwnDelayWaitsAboutOneSecond(): void
    {
        self::assertEqualsWithDelta(1000, $this->waitBeforeRetry(1), 100);
    }

    public function testTheWaitOfAMessageRetriedManyTimesStopsGrowingAtOneMinute(): void
    {
        self::assertSame(60000, $this->waitBeforeRetry(20));
    }

    private function waitBeforeRetry(int $retries): int
    {
        $strategies = self::getContainer()->get('messenger.retry_strategy_locator');
        assert($strategies instanceof ContainerInterface);
        $strategy = $strategies->get('async');
        assert($strategy instanceof RetryStrategyInterface);

        return $strategy->getWaitingTime(new Envelope(new stdClass(), [new RedeliveryStamp($retries - 1)]), ReviewerRateLimited::after(null));
    }
}
