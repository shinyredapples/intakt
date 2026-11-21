<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\ReviewedRound;
use Intakt\Core\Dashboard\Domain\ReviewTrends;
use Intakt\Core\Dashboard\Domain\UpNext;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Uid\Uuid;
use Throwable;
use Twig\Environment;

// One worker at a time reads the rounds and publishes the Trends figures and Up next, because two that read and published unordered could leave the page on the older figures.
final readonly class LiveTrends
{
    public const string TEMPLATE = 'dashboard/stream/trends.stream.html.twig';
    public const string TARGET_WITH_DEMO = 'trends-figures-with-demo';
    public const string TARGET_WITHOUT_DEMO = 'trends-figures-without-demo';
    public const string HEAD_WITH_DEMO = 'trends-head-with-demo';
    public const string HEAD_WITHOUT_DEMO = 'trends-head-without-demo';

    private const string LOCK = 'dashboard-trends';

    public function __construct(
        private LedgerReviewPages $pages,
        private Environment $twig,
        private LiveUpdates $updates,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    // Without a round when a replayed row names none, such as a call's settled usage.
    public function publishLatest(?Uuid $round): void
    {
        try {
            $this->publishedInTurn();
            // Boundary: logs a failed publish and swallows it, because the page is a view and a round update or a replayed row must never change a verdict or a round.
        } catch (Throwable $failure) {
            $this->logger->warning('The trends were not published.', ['round' => $round?->toRfc4122(), 'exception' => $failure]);
        }
    }

    private function publishedInTurn(): void
    {
        $lock = $this->locks->createLock(self::LOCK);
        $published = null;

        // Tried without waiting, because the worker holding the lock reads the state again after it lets go, and so publishes what this one would have found.
        while ($lock->acquire(false)) {
            try {
                $published = $this->publishedOnce($published);
            } finally {
                $lock->release();
            }

            if ($this->signatureOf($this->pages->everyRound(), $this->upNext()) === $published) {
                return;
            }
        }
    }

    private function publishedOnce(?string $published): string
    {
        $rounds = $this->pages->everyRound();
        $upNext = $this->upNext();
        $signature = $this->signatureOf($rounds, $upNext);

        if ($signature !== $published) {
            $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $this->twig->render(self::TEMPLATE, [
                'variants' => [
                    ['head' => self::HEAD_WITH_DEMO, 'target' => self::TARGET_WITH_DEMO, 'trends' => ReviewTrends::counting($rounds)],
                    ['head' => self::HEAD_WITHOUT_DEMO, 'target' => self::TARGET_WITHOUT_DEMO, 'trends' => ReviewTrends::leavingOutTheDemo($rounds)],
                ],
                'up_next' => $upNext,
            ])));
        }

        return $signature;
    }

    private function upNext(): UpNext
    {
        return UpNext::of($this->pages->board());
    }

    // The read data is compared, not the rendered streams, because a rendered term carries a fresh random id each time and two renders never match.
    /**
     * @param list<ReviewedRound> $rounds
     */
    private function signatureOf(array $rounds, UpNext $upNext): string
    {
        return serialize([$rounds, $upNext]);
    }
}
