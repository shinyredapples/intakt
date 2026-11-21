<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
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
            'follow' => $this->takesPlay($request),
        ]));
    }

    // Taken here, so the mark Play left is spent on the page its redirect reaches and a later visit stays on the demo page.
    private function takesPlay(Request $request): bool
    {
        if (!$request->hasPreviousSession()) {
            return false;
        }

        $session = $request->getSession();

        return $session instanceof FlashBagAwareSessionInterface && [] !== $session->getFlashBag()->get(PlayDemoController::STARTED);
    }
}
