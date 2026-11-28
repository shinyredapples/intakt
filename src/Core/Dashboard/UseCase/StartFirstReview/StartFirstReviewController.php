<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StartFirstReview;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\SetupStep;
use Intakt\Core\Dashboard\Infrastructure\FormPress;
use Intakt\Core\Dashboard\UseCase\StartReview\ReviewStarts;
use Intakt\Core\Dashboard\UseCase\RunSetup\RunSetupController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The Host check and the CSRF token guard this route, because the dashboard has no login and reaching its port decides who can act (design: Dashboard, Who can act).
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
        $answer = $this->starts->startSampleChange() ?? throw new NotFoundHttpException('This install has no sample change to review, so nothing was started.');
        $this->press->flash($request, $answer->line);

        // Back to the wizard's Ready step when nothing started, because its flash line says why and the step still offers the start.
        $target = null === $answer->round
            ? $this->urls->generate(DashboardRoutes::RUN_SETUP, [RunSetupController::STEP => SetupStep::Ready->value])
            : $this->urls->generate(DashboardRoutes::SHOW_REVIEW, ['round' => $answer->round]);

        return new RedirectResponse($target, Response::HTTP_SEE_OTHER);
    }
}
