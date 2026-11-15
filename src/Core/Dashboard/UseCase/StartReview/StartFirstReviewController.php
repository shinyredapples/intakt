<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StartReview;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\FormPress;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// Limit: the owner session of the dashboard design does not exist yet, so the Host check and the CSRF token are all that guard this route.
final readonly class StartFirstReviewController
{
    public const string TOKEN_ID = 'dashboard_start_first_review';

    public function __construct(
        private ReviewStarts $starts,
        private FormPress $press,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/setup/first-review', name: DashboardRoutes::START_FIRST_REVIEW, methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $this->press->refuseWithoutToken($request, self::TOKEN_ID, 'The form token is missing or wrong, so nothing was started.');
        $line = $this->starts->startSampleChange() ?? throw new NotFoundHttpException('This install has no sample change to review, so nothing was started.');
        $this->press->flash($request, $line);

        return new RedirectResponse($this->urls->generate(DashboardRoutes::FIRST_RUN), Response::HTTP_SEE_OTHER);
    }
}
