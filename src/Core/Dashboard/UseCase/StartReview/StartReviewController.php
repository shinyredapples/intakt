<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StartReview;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

// Limit: the owner session of the dashboard design does not exist yet, so the Host check and the CSRF token are all that guard this route.
final readonly class StartReviewController
{
    public const string TOKEN_ID = 'dashboard_start_review';

    public function __construct(
        private ReviewStarts $starts,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/reviews/{round}/start', name: DashboardRoutes::START_REVIEW, methods: ['POST'])]
    public function __invoke(Request $request, string $round): Response
    {
        $this->refuseWithoutToken($request);
        $line = $this->starts->startNextOf($round, $this->expectedNumber($request)) ?? throw new NotFoundHttpException('The ledger holds no review with this round that can be started again.');
        $this->flash($request, $line);

        return new RedirectResponse($this->urls->generate(DashboardRoutes::SHOW_REVIEW, ['round' => $round]), Response::HTTP_SEE_OTHER);
    }

    private function refuseWithoutToken(Request $request): void
    {
        $token = $request->request->get('_token');

        if (!is_string($token) || !$this->tokens->isTokenValid(new CsrfToken(self::TOKEN_ID, $token))) {
            throw new BadRequestHttpException('The form token is missing or wrong, so nothing was started.');
        }
    }

    // The number the page showed and not the ledger's at the time of the post, because only that refuses a second press or a page gone stale after another round started; it is only compared, never used to read anything.
    private function expectedNumber(Request $request): int
    {
        $number = filter_var($request->request->get('number'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return is_int($number) ? $number : throw new BadRequestHttpException('The form names no round number, so nothing was started.');
    }

    /**
     * @param array{string, string} $line
     */
    private function flash(Request $request, array $line): void
    {
        $session = $request->getSession();
        assert($session instanceof FlashBagAwareSessionInterface);
        $session->getFlashBag()->add(...$line);
    }
}
