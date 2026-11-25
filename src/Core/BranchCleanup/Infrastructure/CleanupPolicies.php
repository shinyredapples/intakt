<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Infrastructure;

use Intakt\Core\BranchCleanup\Contract\BranchCleanupServices;
use Intakt\Core\BranchCleanup\Domain\CleanupPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class CleanupPolicies
{
    /**
     * @param array<string, array{clean_other_branches: bool, keep_branches: list<string>}> $projects
     */
    public function __construct(
        #[Autowire(param: BranchCleanupServices::PROJECTS)]
        private array $projects,
    ) {
    }

    // A project that sets neither key gets the safe defaults (Intakt's own branches only, none kept by name), so other people's branches need an explicit opt-in.
    public function of(string $project): CleanupPolicy
    {
        $settings = $this->projects[$project] ?? ['clean_other_branches' => false, 'keep_branches' => []];

        return new CleanupPolicy($settings['clean_other_branches'], $settings['keep_branches']);
    }
}
