<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Every read throws when it fails or cannot answer, because cleanup would read a failure as "no pull request" or "no commit by a person" and delete.
interface ForgeBranches
{
    /**
     * @return list<ForgeBranch>
     *
     * @throws ForgeReadFailed
     */
    public function branches(): array;

    /**
     * @throws ForgeReadFailed
     */
    public function changeOf(ForgeBranch $branch): ?BranchChange;

    /**
     * @throws ForgeReadFailed
     */
    public function hasCommitByAPerson(ForgeBranch $branch, string $intaktIdentity): bool;

    // Only at the listed head, so a branch that moved since the listing is kept.
    /**
     * @throws ForgeWriteFailed
     */
    public function delete(ForgeBranch $branch): void;
}
