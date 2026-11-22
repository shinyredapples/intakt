<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ListWaiting;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\WaitingPageReading;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListWaitingController
{
    public function __construct(
        private WaitingPageReading $reading,
        private LivePageRendering $pages,
        private FilterProjects $filterProjects,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/waiting', name: DashboardRoutes::LIST_WAITING, methods: ['GET'])]
    // Outage notices belong to no project, so they stay on the page under every project filter.
    public function __invoke(Request $request, #[MapQueryParameter] ?string $project = null): Response
    {
        $page = $this->reading->read();

        // The shell's You count comes from this list, because a second read could miss a notice that ended between the two and show the item without a count.
        return new Response($this->pages->render($request, 'dashboard/waiting.html.twig', [
            'page' => $page,
            'waiting_listed' => count($page->items),
            'now' => $this->clock->now(),
            'linked_project' => $project,
            'project_trail' => in_array($project, $this->filterProjects->names(), true) ? $project : null,
        ]));
    }
}
