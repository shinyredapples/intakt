<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Budget\Contract\OwnerNoticesChanged;
use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\WaitingPage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Lock\LockFactory;
use Throwable;
use Twig\Environment;

// One worker at a time reads the page and publishes it, because two that read and published unordered could leave an item on every open page after its outage ended.
#[AsEventListener(event: OwnerNoticesChanged::class, method: 'publishLatest')]
final readonly class LiveWaitingPage
{
    public const string TEMPLATE = 'dashboard/stream/waiting_page.stream.html.twig';

    // One for the install, because every notice and every round changes the same page on the same topic.
    private const string LOCK = 'dashboard-waiting-page';

    public function __construct(
        private WaitingPageReading $reading,
        private Environment $twig,
        private LiveUpdates $updates,
        private LockFactory $locks,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function publishLatest(): void
    {
        try {
            $this->publishedInTurn();
            // Boundary: logs a failed page and swallows it, because the page is a view and a notice, a reading or a round must never fail over it.
        } catch (Throwable $failure) {
            $this->logger->warning('The waiting page was not published.', ['exception' => $failure]);
        }
    }

    private function publishedInTurn(): void
    {
        $lock = $this->locks->createLock(self::LOCK);
        $published = null;

        // Tried without waiting, because the worker holding the lock reads the page again after it lets go, and so publishes what this one would have found.
        while ($lock->acquire(false)) {
            try {
                $published = $this->publishedOnce($published);
            } finally {
                $lock->release();
            }

            if ($this->signatureOf($this->reading->read()) === $published) {
                return;
            }
        }
    }

    private function publishedOnce(?string $published): string
    {
        $page = $this->reading->read();
        $signature = $this->signatureOf($page);

        if ($signature !== $published) {
            $this->updates->publish(new LiveEvent(DashboardTopics::WAITING, $this->twig->render(self::TEMPLATE, ['page' => $page, 'now' => $this->clock->now()])));
        }

        return $signature;
    }

    // The pages are compared, not the rendered streams, because a render carries the time waited so far, and two renders of one page a minute apart never match.
    private function signatureOf(WaitingPage $page): string
    {
        return serialize($page);
    }
}
