<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\FirstRun;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoRuns;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FirstRunController
{
    public function __construct(
        private FirstRunSteps $steps,
        private LivePageRendering $pages,
        private DemoRuns $demo,
    ) {
    }

    #[Route('/setup', name: DashboardRoutes::FIRST_RUN, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return new Response($this->pages->render($request, 'dashboard/first_run.html.twig', [
            'first_run' => $this->steps->firstRun(),
            'demo' => $this->demo->readiness(),
        ]));
    }
}
