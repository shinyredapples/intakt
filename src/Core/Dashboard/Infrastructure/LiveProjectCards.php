<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Contract\RefusalLogging;
use Intakt\Core\Dashboard\Domain\ProjectCard;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Throwable;
use Twig\Environment;

// One worker at a time reads the cards and publishes them, because two that read and published unordered could leave an older list on every open page.
final readonly class LiveProjectCards
{
    public const string TEMPLATE = 'dashboard/stream/project_cards.stream.html.twig';

    // One for the install, because every worker publishes the same cards on the same topic.
    private const string LOCK = 'dashboard-project-cards';

    public function __construct(
        private ProjectCards $cards,
        private Environment $twig,
        private LiveUpdates $updates,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    public function publishLatest(): void
    {
        try {
            $this->publishedInTurn();
            // Boundary: logs a failed update and swallows it, because the page is a view and a round update must never change a verdict or a round.
        } catch (Throwable $failure) {
            $this->logger->warning('The project cards were not published.', ['exception' => $failure]);
        }
    }

    private function publishedInTurn(): void
    {
        $lock = $this->locks->createLock(self::LOCK);
        $retried = false;

        // Tried without waiting, because the worker holding the lock reads the cards again after it lets go, and so publishes what this one would have found.
        while ($lock->acquire(false)) {
            try {
                if ($this->publishedAndUnchanged($lock)) {
                    return;
                }
                // Boundary: a worker that yielded while this one held the lock counts on it to publish, so a failed read, render or send is tried once more before the failure is passed on.
            } catch (Throwable $failure) {
                if ($retried) {
                    throw $failure;
                }

                $retried = true;
            }
        }
    }

    // Compared after the lock is let go, because a worker that changed the cards while this one held it yielded and relies on this read.
    private function publishedAndUnchanged(LockInterface $lock): bool
    {
        try {
            $published = $this->publishedOnce();
        } finally {
            $lock->release();
        }

        return $this->signatureOf($this->cards->now()) === $published;
    }

    private function publishedOnce(): string
    {
        $cards = $this->cards->now();

        // A send the hub did not take fails like a failed render, because a worker that yielded counts on these cards reaching the open pages.
        if (!$this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $this->twig->render(self::TEMPLATE, ['projects' => $cards]), RefusalLogging::ByCaller))) {
            throw new ProjectCardsNotSent();
        }

        return $this->signatureOf($cards);
    }

    // The cards are compared, not the rendered streams, because a rendered term's tooltip carries a fresh random id each time.
    /**
     * @param list<ProjectCard> $cards
     */
    private function signatureOf(array $cards): string
    {
        return hash('xxh128', serialize($cards));
    }
}
