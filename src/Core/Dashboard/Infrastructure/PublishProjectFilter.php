<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\RoundOpened;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Throwable;
use Twig\Environment;

// Only on a project's first round, because every publication resets the select of an open page; a project whose build work comes first is listed at the next page load, since a build step publishes nothing.
#[AsCommandHandler]
final readonly class PublishProjectFilter
{
    public const string TEMPLATE = 'dashboard/stream/project_filter.stream.html.twig';

    // One for the install, because every worker publishes the same filter on the same topic.
    private const string LOCK = 'dashboard-project-filter';

    public function __construct(
        private FilterProjects $projects,
        private Environment $twig,
        private LiveUpdates $updates,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RoundOpened $opened): void
    {
        try {
            if ($this->projects->isFirstRoundOf($opened->round, $opened->project)) {
                $this->publishLatest();
            }
            // Boundary: logs a failed filter update and swallows it, because the round is already committed and a failed live update must not send its start back to the queue.
        } catch (Throwable $failure) {
            $this->logger->warning('The project filter was not published.', ['round' => $opened->round, 'project' => $opened->project, 'exception' => $failure]);
        }
    }

    // One worker at a time reads the projects and publishes them, because two that read and published unordered could leave an older list, without the newer round's project, on every open page.
    private function publishLatest(): void
    {
        $lock = $this->locks->createLock(self::LOCK);
        $published = null;

        // Tried without waiting, because the worker holding the lock reads the projects again after it lets go, and so publishes what this one would have found.
        while ($lock->acquire(false)) {
            try {
                $published = $this->publishedOnce($published);
            } finally {
                $lock->release();
            }

            if ($this->projects->names() === $published) {
                return;
            }
        }
    }

    /**
     * @param list<string>|null $published
     *
     * @return list<string>
     */
    private function publishedOnce(?array $published): array
    {
        $names = $this->projects->names();

        if ($names !== $published) {
            $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $this->twig->render(self::TEMPLATE, ['projects' => $names])));
        }

        return $names;
    }
}
