<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowComponents;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class ShowComponentsController
{
    public function __construct(
        private Environment $twig,
    ) {
    }

    #[Route('/components', name: DashboardRoutes::SHOW_COMPONENTS, methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response($this->twig->render('dashboard/components.html.twig'));
    }
}
