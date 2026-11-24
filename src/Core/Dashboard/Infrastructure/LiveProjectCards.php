<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\Domain\ProjectCard;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
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

        // Tried without waiting, because the worker holding the lock reads the cards again after it lets go, and so publishes what this one would have found.
        while ($lock->acquire(false)) {
            try {
                $published = $this->publishedOnce();
            } finally {
                $lock->release();
            }

            if ($this->signatureOf($this->cards->now()) === $published) {
                return;
            }
        }
    }

    private function publishedOnce(): string
    {
        $cards = $this->cards->now();
        $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $this->twig->render(self::TEMPLATE, ['projects' => $cards])));

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
