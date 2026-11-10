<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\DashboardTopics;
use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Review\Contract\RoundOpened;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Throwable;
use Twig\Environment;

// On every opened round, because a round's start is the only ledger row that adds a project to the filter.
#[AsEventListener]
final readonly class PublishProjectFilter
{
    public const string TEMPLATE = 'dashboard/stream/project_filter.stream.html.twig';

    public function __construct(
        private FilterProjects $projects,
        private Environment $twig,
        private LiveUpdates $updates,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RoundOpened $opened): void
    {
        try {
            $stream = $this->twig->render(self::TEMPLATE, ['projects' => $this->projects->names()]);
        } catch (Throwable $failure) {
            // Swallowed after a log, because the round is already committed and a failed live update must not send its start back to the queue.
            $this->logger->warning('The project filter was not published.', ['round' => $opened->round, 'project' => $opened->project, 'exception' => $failure]);

            return;
        }

        $this->updates->publish(new LiveEvent(DashboardTopics::SHELL, $stream));
    }
}
