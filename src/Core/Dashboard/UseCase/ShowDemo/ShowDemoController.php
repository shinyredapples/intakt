<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowDemo;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoRuns;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowDemoController
{
    public function __construct(
        private DemoRuns $runs,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/demo', name: DashboardRoutes::SHOW_DEMO, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        return new Response($this->pages->render($request, 'dashboard/demo.html.twig', [
            'readiness' => $this->runs->readiness(),
            'play' => DemoRuns::playOf($request->query->getString(DemoRuns::PLAY)),
            'started_flash' => DemoRuns::startedFlash($request->query->getString(DemoRuns::PLAY)),
        ]));
    }
}
