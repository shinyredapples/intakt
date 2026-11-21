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

    // A flash type only this controller writes, because another form's success line that a demo request picks up first must not make that visit follow a replay another tab started; ShowDemoController takes it, so only the page this redirect reaches carries the tour's follow mark.
    public const string STARTED = 'demo_started';

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
        $line = $this->runs->play();
        $this->press->flash($request, $line);

        if (DemoRuns::FLASH_SUCCESS === $line[0]) {
            $this->press->flash($request, [self::STARTED, '1']);
        }

        return new RedirectResponse($this->urls->generate(DashboardRoutes::SHOW_DEMO), Response::HTTP_SEE_OTHER);
    }
}
