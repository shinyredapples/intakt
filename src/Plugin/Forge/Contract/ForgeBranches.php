<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

// Tagged, because each project lives on one forge and branch cleanup asks every forge which projects it serves.
#[AutoconfigureTag]
interface ForgeBranches
{
    public function servesProject(string $project): bool;

    /**
     * @return list<ForgeBranch>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function branchesOf(string $project): array;

    // Null when no pull request was opened for the branch's current head, because one made for an older head says nothing about commits pushed after it.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function changeOf(string $project, ForgeBranch $branch): ?BranchChange;

    // Only while the branch is still at the listed head, because a push after the warning brought commits nobody was warned about.
    /**
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    public function deleteBranch(string $project, ForgeBranch $branch): void;

    // A read that cannot answer throws, because "no commit by a person" would let cleanup delete someone's work.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function hasCommitByPerson(string $project, ForgeBranch $branch): bool;
}
