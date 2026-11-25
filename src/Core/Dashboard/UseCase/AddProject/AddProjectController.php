<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\AddProject;

use Intakt\Core\Dashboard\Contract\DashboardRoutes;
use Intakt\Core\Dashboard\Domain\AddProjectStep;
use Intakt\Core\Dashboard\Domain\RepositoryField;
use Intakt\Core\Dashboard\Domain\ShareAndLabelField;
use Intakt\Core\Dashboard\Infrastructure\LivePageRendering;
use Intakt\Plugin\GitHubWorkSource\Contract\WorkLabels;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

// One page for every step, chosen by ?step=, because each step is a plain link that works without a script (mock add-project.html).
final readonly class AddProjectController
{
    public const string STEP = 'step';
    public const string REPOSITORY = 'repository';
    public const string SHARE = 'share';
    public const string LABEL = 'label';

    public function __construct(
        private BootChecks $checks,
        private LivePageRendering $pages,
    ) {
    }

    // The checks are posted, because a pasted address may hold credentials and a query would put them in the browser's history; they keep nothing, so they need no token.
    #[Route('/projects/new', name: DashboardRoutes::ADD_PROJECT, methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $step = AddProjectStep::fromQuery($this->text($request->query->all()[self::STEP] ?? ''));
        $posted = $request->isMethod('POST') ? $request->request->all() : [];

        return new Response($this->pages->render($request, 'dashboard/add_project.html.twig', [
            'step' => $step,
            'steps' => AddProjectStep::cases(),
            'repository' => $this->repositoryOf($posted, $step),
            'share_and_label' => $this->shareAndLabelOf($posted, $step),
            'failing_mains' => AddProjectStep::Check === $step ? $this->checks->failingMains() : [],
            'rail_section' => 'projects',
        ]));
    }

    // A field sent as a list is no step and no value, so it reads as empty instead of failing the request.
    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @param array<array-key, mixed> $posted
     */
    private function repositoryOf(array $posted, AddProjectStep $step): RepositoryField
    {
        if (AddProjectStep::Repository !== $step || !array_key_exists(self::REPOSITORY, $posted)) {
            return RepositoryField::unsubmitted();
        }

        return RepositoryField::submitted($this->text($posted[self::REPOSITORY]));
    }

    /**
     * @param array<array-key, mixed> $posted
     */
    private function shareAndLabelOf(array $posted, AddProjectStep $step): ShareAndLabelField
    {
        if (AddProjectStep::ShareAndLabel !== $step || !array_key_exists(self::SHARE, $posted) && !array_key_exists(self::LABEL, $posted)) {
            return ShareAndLabelField::unsubmitted(WorkLabels::ELIGIBILITY);
        }

        return ShareAndLabelField::submitted($this->text($posted[self::SHARE] ?? ''), $this->text($posted[self::LABEL] ?? ''));
    }
}
