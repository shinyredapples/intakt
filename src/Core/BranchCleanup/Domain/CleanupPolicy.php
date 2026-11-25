<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A project's own choices, read from its configuration, because whether other people's branches may go and which are kept by name is the project's to decide.
#[Exclude]
final readonly class CleanupPolicy
{
    // Spelled once, so the branch names and the cleanup agree (docs/design/05-architecture/08-merge.md#branch-cleanup).
    public const string INTAKT_PREFIX = 'intakt/';

    /**
     * @param list<string> $keepBranches
     */
    public function __construct(
        public bool $cleansOtherBranches,
        public array $keepBranches,
    ) {
    }

    public function mayTouch(string $branch): bool
    {
        return $this->cleansOtherBranches || str_starts_with($branch, self::INTAKT_PREFIX);
    }

    public function keepsByName(string $branch): bool
    {
        return in_array($branch, $this->keepBranches, true);
    }
}
