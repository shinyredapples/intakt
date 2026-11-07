<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ListReviews;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// A redirect and not the page itself, because the instance check requests `/` on a database that is not migrated, where reading the ledger answers 500.
final readonly class OpenDashboardController
{
    public function __construct(
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/', name: DashboardRoutes::OPEN_DASHBOARD, methods: ['GET'])]
    public function __invoke(): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(DashboardRoutes::LIST_REVIEWS));
    }
}
