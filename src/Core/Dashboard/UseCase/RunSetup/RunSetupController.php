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

    // The address check is posted, because a pasted address may hold credentials and a query would put them in the browser's history; it keeps nothing, so it needs no token.
    #[Route('/setup', name: DashboardRoutes::RUN_SETUP, methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $step = SetupStep::fromQuery($this->text($request->query->all()[self::STEP] ?? ''));

        return new Response($this->pages->render($request, 'dashboard/setup.html.twig', [
            'step' => $step,
            'steps' => SetupStep::cases(),
            'repository' => $this->repositoryOf($request, $step),
            'first_run' => $this->steps->firstRun(),
            'demo' => $this->demo->readiness(),
        ]));
    }

    // A field sent as a list is no step and no address, so it reads as empty instead of failing the request.
    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function repositoryOf(Request $request, SetupStep $step): RepositoryField
    {
        $fields = $request->request->all();

        if (SetupStep::Repository !== $step || !$request->isMethod('POST') || !array_key_exists(self::REPOSITORY, $fields)) {
            return RepositoryField::unsubmitted();
        }

        return RepositoryField::submitted($this->text($fields[self::REPOSITORY]));
    }
}
