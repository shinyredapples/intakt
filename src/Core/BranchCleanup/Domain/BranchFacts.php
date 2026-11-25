<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

use DateTimeImmutable;
use Intakt\Plugin\Forge\Contract\BranchChange;
use Intakt\Plugin\Forge\Contract\ForgeBranch;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// What the forge says about one branch, gathered before the rules run so the rules do no I/O.
#[Exclude]
final readonly class BranchFacts
{
    // A null $committedByAPerson means the check could not answer, and that claims the branch, because a failed read must not delete.
    public function __construct(
        public ForgeBranch $branch,
        public ?BranchChange $change,
        public DateTimeImmutable $lastCommitAt,
        public ?bool $committedByAPerson,
        public ?DateTimeImmutable $closedAt = null,
    ) {
    }
}
