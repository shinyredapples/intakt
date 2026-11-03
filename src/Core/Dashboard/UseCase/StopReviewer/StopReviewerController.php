<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\StopReviewer;

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

// Limit: the owner session of the dashboard design does not exist yet, so the Host check and the CSRF token are all that guard this route, as on the start route.
final readonly class StopReviewerController
{
    public const string TOKEN_ID = 'dashboard_stop_reviewer';

    public function __construct(
        private ReviewerStops $stops,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/reviews/{round}/reviewers/{reviewer}/stop', name: DashboardRoutes::STOP_REVIEWER, methods: ['POST'])]
    public function __invoke(Request $request, string $round, string $reviewer): Response
    {
        $this->refuseWithoutToken($request);
        $line = $this->stops->request($round, $reviewer) ?? throw new NotFoundHttpException('The ledger shows no running reviewer with this id in this round.');
        $this->flash($request, $line);

        return new RedirectResponse($this->urls->generate(DashboardRoutes::SHOW_REVIEW, ['round' => $round]), Response::HTTP_SEE_OTHER);
    }

    private function refuseWithoutToken(Request $request): void
    {
        $token = $request->request->get('_token');

        if (!is_string($token) || !$this->tokens->isTokenValid(new CsrfToken(self::TOKEN_ID, $token))) {
            throw new BadRequestHttpException('The form token is missing or wrong, so nothing was stopped.');
        }
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
