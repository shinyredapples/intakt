<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ListWaiting;

use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\WaitingItem;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListWaitingController
{
    public function __construct(
        private OwnerNotices $notices,
        private LivePageRendering $pages,
        private FilterProjects $filterProjects,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/waiting', name: DashboardRoutes::LIST_WAITING, methods: ['GET'])]
    // Outage notices belong to no project, so they stay on the page under every project filter.
    public function __invoke(Request $request, #[MapQueryParameter] ?string $project = null): Response
    {
        $now = $this->clock->now();

        return new Response($this->pages->render($request, 'dashboard/waiting.html.twig', [
            'items' => array_map(static fn (OutageNotice $notice): WaitingItem => WaitingItem::ofOutage($notice), $this->notices->oldestFirst()),
            'now' => $now,
            'linked_project' => $project,
            'project_trail' => in_array($project, $this->filterProjects->names(), true) ? $project : null,
        ]));
    }
}
