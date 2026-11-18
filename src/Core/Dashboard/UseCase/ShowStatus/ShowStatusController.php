<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowStatus;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

// A dashboard route, so RefuseForeignHost answers it only under the dashboard's own host; the document is passed on as the read model encoded it, so it matches intakt:status byte for byte.
final readonly class ShowStatusController
{
    public function __construct(
        private StatusReadModel $status,
    ) {
    }

    #[Route('/status', name: DashboardRoutes::SHOW_STATUS, methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return JsonResponse::fromJsonString($this->status->json());
    }
}
