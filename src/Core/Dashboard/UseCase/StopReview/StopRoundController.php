<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StopReview;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\FormPress;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The Host check, the CSRF token and the ledger check guard this route, because the dashboard has no login and reaching its port decides who can act (design: Dashboard, Who can act).
final readonly class StopRoundController
{
    public const string TOKEN_ID = 'dashboard_stop_round';

    public function __construct(
        private ReviewerStops $stops,
        private FormPress $press,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/reviews/{round}/stop', name: DashboardRoutes::STOP_ROUND, methods: ['POST'])]
    public function __invoke(Request $request, string $round): Response
    {
        $this->press->refuseWithoutToken($request, self::TOKEN_ID, 'The form token is missing or wrong, so nothing was stopped.');
        $line = $this->stops->requestStopOfRound($round) ?? throw new NotFoundHttpException('The ledger shows no round still in review with this id.');
        $this->press->flash($request, $line);

        return new RedirectResponse($this->urls->generate(DashboardRoutes::SHOW_REVIEW, ['round' => $round]), Response::HTTP_SEE_OTHER);
    }
}
