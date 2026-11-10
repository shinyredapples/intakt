<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ListProjects;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListProjectsController
{
    public function __construct(
        private LedgerReviews $reviews,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/projects', name: DashboardRoutes::LIST_PROJECTS, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $projects = ProjectSummary::fromReviews($this->reviews->newestFirst());

        return new Response($this->pages->render($request, 'dashboard/projects.html.twig', ['projects' => $projects]));
    }
}
