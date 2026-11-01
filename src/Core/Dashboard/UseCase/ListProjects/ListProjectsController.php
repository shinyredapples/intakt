<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ListProjects;

use Intakt\Core\Dashboard\Domain\ProjectSummary;
use Intakt\Core\Dashboard\Infrastructure\LedgerReviews;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

final readonly class ListProjectsController
{
    public function __construct(
        private LedgerReviews $reviews,
        private Environment $twig,
    ) {
    }

    #[Route('/projects', name: 'dashboard_list_projects', methods: ['GET'])]
    public function __invoke(): Response
    {
        $projects = ProjectSummary::fromReviews($this->reviews->newestFirst());

        return new Response($this->twig->render('dashboard/projects.html.twig', ['projects' => $projects]));
    }
}
