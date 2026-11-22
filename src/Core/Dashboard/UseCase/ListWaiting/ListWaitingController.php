<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ListWaiting;

use Intakt\Core\Budget\Contract\OutageNotice;
use Intakt\Core\Budget\Contract\OwnerNotices;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Contract\DashboardServices;
use Intakt\Core\Dashboard\Domain\WaitingItem;
use Intakt\Core\Dashboard\Infrastructure\FilterProjects;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListWaitingController
{
    /**
     * @param array<string, string> $statusPages
     */
    public function __construct(
        private OwnerNotices $notices,
        private LivePageRendering $pages,
        private FilterProjects $filterProjects,
        private ClockInterface $clock,
        #[Autowire(param: DashboardServices::STATUS_PAGES)]
        private array $statusPages,
    ) {
    }

    #[Route('/waiting', name: DashboardRoutes::LIST_WAITING, methods: ['GET'])]
    // Outage notices belong to no project, so they stay on the page under every project filter.
    public function __invoke(Request $request, #[MapQueryParameter] ?string $project = null): Response
    {
        $now = $this->clock->now();
        $items = array_map(
            fn (OutageNotice $notice): WaitingItem => WaitingItem::ofOutage($notice, $this->statusPages[$notice->account->provider->value] ?? null),
            $this->notices->stillRunningOldestFirst(),
        );

        // The shell's You count comes from this list, because a second read could miss a notice that ended between the two and show the item without a count.
        return new Response($this->pages->render($request, 'dashboard/waiting.html.twig', [
            'items' => $items,
            'waiting_listed' => count($items),
            'now' => $now,
            'linked_project' => $project,
            'project_trail' => in_array($project, $this->filterProjects->names(), true) ? $project : null,
        ]));
    }
}
