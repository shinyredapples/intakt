<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\UseCase\CleanUpBranches;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\BranchCleanup\Domain\BranchWarning;

final readonly class BranchWarnings
{
    public function __construct(
        private EntityManagerInterface $entities,
    ) {
    }

    /**
     * @return array<string, BranchWarning>
     */
    public function of(string $project): array
    {
        $warnings = [];
        foreach ($this->entities->getRepository(BranchWarning::class)->findBy(['project' => $project]) as $warning) {
            $warnings[$warning->branch()] = $warning;
        }

        return $warnings;
    }

    // In one transaction, so a run that fails half-way leaves the warnings of the run before.
    /**
     * @param list<BranchWarning> $warnings
     */
    public function replace(string $project, array $warnings): void
    {
        $this->entities->wrapInTransaction(function () use ($project, $warnings): void {
            foreach ($this->of($project) as $stored) {
                if (!in_array($stored, $warnings, true)) {
                    $this->entities->remove($stored);
                }
            }

            // Flushed before the new ones, because a branch warned again at a new head keeps its identity, and Doctrine holds one object per identity.
            $this->entities->flush();
            foreach ($warnings as $warning) {
                $this->entities->persist($warning);
            }

            $this->entities->flush();
        });
    }
}
