<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\ShowTask;

use Intakt\Core\Build\Contract\BuildStep;
use Intakt\Core\Build\Contract\BuildStepOutcome;
use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Infrastructure\LedgerBuildSteps;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Core\Dashboard\Infrastructure\ParkedQuestionTexts;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redactor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShowTaskController
{
    public function __construct(
        private Ledger $ledger,
        private LedgerBuildSteps $buildSteps,
        private ParkedQuestionTexts $questions,
        private Redactor $redactor,
        private LivePageRendering $pages,
    ) {
    }

    #[Route('/tasks/{project}/{task}', name: DashboardRoutes::SHOW_TASK, methods: ['GET'])]
    public function __invoke(Request $request, string $project, string $task): Response
    {
        $steps = $this->buildSteps->latestRunOf($this->ledger->readInOrder(), $project, $task);

        if ([] === $steps) {
            throw new NotFoundHttpException('The ledger holds no build step of this task.');
        }

        return new Response($this->pages->render($request, 'dashboard/task.html.twig', [
            'project' => $project,
            'task' => $task,
            'steps' => array_map($this->redacted(...), $steps),
            'unreached' => $this->unreachedAfter($steps),
            'question' => $this->parkedQuestionOf($steps, $project, $task),
        ]));
    }

    // Redacted because a step's failure quotes a git or forge error, which can carry a credential, as the status report does.
    /**
     * @param array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool} $step
     *
     * @return array{project: string, task: string, step: string, outcome: string, failure: ?string, ended_at: string, run: ?string, final: bool}
     */
    private function redacted(array $step): array
    {
        return [...$step, 'failure' => null === $step['failure'] ? null : $this->redactor->redact($step['failure'])];
    }

    // Steps after the last recorded one are listed as not reached, so the page shows where the run stopped among all of them.
    /**
     * @param non-empty-list<array{step: string, final: bool}> $steps
     *
     * @return list<string>
     */
    private function unreachedAfter(array $steps): array
    {
        $cases = array_map(static fn (BuildStep $step): string => $step->value, BuildStep::cases());
        $lastRow = array_last($steps);
        $last = $lastRow['final'] ? count($cases) - 1 : array_search($lastRow['step'], $cases, true);

        return array_slice($cases, false === $last ? count($cases) : $last + 1);
    }

    // Only for a run that ended asking, and only its own question, because the task's row can hold the question of another run that overlapped it.
    /**
     * @param non-empty-list<array{outcome: string, run: ?string}> $steps
     *
     * @return array{text: string, choices: list<string>, recommendation: string, parked_at: string}|null
     */
    private function parkedQuestionOf(array $steps, string $project, string $task): ?array
    {
        $last = array_last($steps);

        return BuildStepOutcome::Asked->value === $last['outcome'] ? $this->questions->of($project, $task, $last['run']) : null;
    }
}
