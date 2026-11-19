<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StopReview;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\FormPress;
use Intakt\Core\Dashboard\Infrastructure\RoundLock;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The Host check, the CSRF token and the ledger check guard this route, because the dashboard has no login and reaching its port decides who can act (design: Dashboard, Who can act).
final readonly class StopReviewController
{
    public const string TOKEN_ID = 'dashboard_stop_review';

    private const array BUSY = ['error', 'Another press for this round is still running, so nothing was stopped.'];

    public function __construct(
        private ReviewerStops $stops,
        private FormPress $press,
        private UrlGeneratorInterface $urls,
    ) {
    }

    // The reviewer is read from the request and not taken as an argument, because the lock's failure takes the third argument and a fourth would pass the parameter limit.
    #[Route('/reviews/{round}/reviewers/{reviewer}/stop', name: DashboardRoutes::STOP_REVIEW, methods: ['POST'])]
    #[RoundLock]
    public function __invoke(Request $request, string $round, ?LockConflictedException $busy = null): Response
    {
        $this->press->refuseWithoutToken($request, self::TOKEN_ID, 'The form token is missing or wrong, so nothing was stopped.');
        $line = $busy instanceof LockConflictedException ? self::BUSY : $this->stoppedLine($round, $request->attributes->getString('reviewer'));
        $this->press->flash($request, $line);

        return new RedirectResponse($this->urls->generate(DashboardRoutes::SHOW_REVIEW, ['round' => $round]), Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array{string, string}
     */
    private function stoppedLine(string $round, string $reviewer): array
    {
        return $this->stops->requestStopOf($round, $reviewer) ?? throw new NotFoundHttpException('The ledger shows no running reviewer with this id in an unjudged round.');
    }
}
