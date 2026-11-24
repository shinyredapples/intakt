<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ListProjects;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\ProjectCards;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ListProjectsController
{
    public function __construct(
        private ProjectCards $cards,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/projects', name: DashboardRoutes::LIST_PROJECTS, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return new Response($this->pages->render($request, 'dashboard/projects.html.twig', ['projects' => $this->cards->now()]));
    }
}
