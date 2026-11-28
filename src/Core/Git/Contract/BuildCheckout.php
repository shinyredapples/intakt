<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// What a build needs of git: the project's mirror brought up to date, then a clone of it, the mirror's path for the hand-back check, and the checked head taken into the clone.
interface BuildCheckout
{
    /**
     * @throws BuildCheckoutFailed
     */
    public function updateMirror(string $project, string $remote): void;

    // Never held below a disk floor, because the build that asks is already running.
    /**
     * @throws BuildCheckoutFailed
     */
    public function refreshMirror(string $project, string $remote): void;

    /**
     * @throws BuildCheckoutFailed
     */
    public function makeClone(string $project, string $branch, string $directory): void;

    /**
     * @throws BuildCheckoutFailed
     */
    public function mirrorOf(string $project): string;

    /**
     * @throws BuildCheckoutFailed
     */
    public function takeCheckedHead(string $project, BranchHead $head, string $directory): void;
}
