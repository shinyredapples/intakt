<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// A project's own choices, read from its configuration, because whether other people's branches may go and which are kept by name is the project's to decide.
#[Exclude]
final readonly class CleanupPolicy
{
    // The prefix of the branches Intakt creates (docs/design/05-architecture/08-merge.md#branch-cleanup); spelled once, so the branch names and the cleanup agree.
    public const string INTAKT_PREFIX = 'intakt/';

    // `release/1.2`, `releases/1.2` and `release-1.2`, the common spellings, because a release branch is never cleaned up.
    private const string RELEASE = '#^releases?[/-]#';

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

    public function isRelease(string $branch): bool
    {
        return 1 === preg_match(self::RELEASE, $branch);
    }

    public function keepsByName(string $branch): bool
    {
        return in_array($branch, $this->keepBranches, true);
    }
}
