<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RunSetup;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\RepositoryField;
use Intakt\Core\Dashboard\Domain\SetupStep;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\UseCase\PlayDemo\DemoRuns;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// One page for every step, chosen by ?step=, because each step is a plain link that works without a script (mock setup.html).
final readonly class RunSetupController
{
    public const string STEP = 'step';
    public const string REPOSITORY = 'repository';

    public function __construct(
        private SetupSteps $steps,
        private LivePageRendering $pages,
        private DemoRuns $demo,
    ) {
    }

    #[Route('/setup', name: DashboardRoutes::RUN_SETUP, methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $step = SetupStep::fromQuery($request->query->getString(self::STEP));

        return new Response($this->pages->render($request, 'dashboard/setup.html.twig', [
            'step' => $step,
            'steps' => SetupStep::cases(),
            'repository' => $this->repositoryOf($request),
            'first_run' => $this->steps->firstRun(),
            'demo' => $this->demo->readiness(),
        ]));
    }

    private function repositoryOf(Request $request): RepositoryField
    {
        return $request->query->has(self::REPOSITORY) ? RepositoryField::submitted($request->query->getString(self::REPOSITORY)) : RepositoryField::unsubmitted();
    }
}
