<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTeam;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowTeamController
{
    public function __construct(
        private TeamRoster $roster,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/team', name: DashboardRoutes::SHOW_TEAM, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return new Response($this->pages->render($request, 'dashboard/team.html.twig', [
            'team' => $this->roster->now(),
        ]));
    }
}
