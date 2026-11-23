<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StartReview;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\FormIsRefused;
use Intakt\Core\Dashboard\Infrastructure\FormPress;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The Host check and the CSRF token guard this route, because the dashboard has no login and reaching its port decides who can act (design: Dashboard, Who can act).
final readonly class StartReviewController
{
    public const string TOKEN_ID = 'dashboard_start_review';

    public function __construct(
        private ReviewStarts $starts,
        private FormPress $press,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/reviews/{round}/start', name: DashboardRoutes::START_REVIEW, methods: ['POST'])]
    public function __invoke(Request $request, string $round): Response
    {
        $this->press->refuseWithoutToken($request, self::TOKEN_ID, 'The form token is missing or wrong, so nothing was started.');
        $answer = $this->starts->startNextOf($round, $this->expectedNumber($request)) ?? throw new NotFoundHttpException('The ledger holds no review with this round that can be started again.');
        $this->press->flash($request, $answer->line);

        return new RedirectResponse($this->urls->generate(DashboardRoutes::SHOW_REVIEW, ['round' => $round]), Response::HTTP_SEE_OTHER);
    }

    // The number the page showed and not the ledger's at the time of the post, because only that refuses a second press or a page gone stale after another round started; it is only compared, never used to read anything.
    private function expectedNumber(Request $request): int
    {
        $number = filter_var($request->request->get('number'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($number) ? $number : throw new FormIsRefused('The form names no round number, so nothing was started.');
    }
}
