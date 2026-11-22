<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\FormPress;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The Host check and the CSRF token guard this route, because the dashboard has no login and reaching its port decides who can act (design: Dashboard, Who can act).
final readonly class PlayDemoController
{
    public const string TOKEN_ID = 'dashboard_play_demo';

    public function __construct(
        private DemoRuns $runs,
        private FormPress $press,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/demo', name: DashboardRoutes::PLAY_DEMO, methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $this->press->refuseWithoutToken($request, self::TOKEN_ID, 'The form token is missing or wrong, so the demo run was not started.');
        $play = bin2hex(random_bytes(16));
        $line = $this->runs->play($play);
        $this->press->flash($request, $line);
        $query = DemoRuns::startedFlash($play) === $line[0] ? [DemoRuns::PLAY => $play] : [];

        return new RedirectResponse($this->urls->generate(DashboardRoutes::SHOW_DEMO, $query), Response::HTTP_SEE_OTHER);
    }
}
