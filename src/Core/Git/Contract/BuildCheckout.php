<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// What a build needs of git before its job starts: the project's mirror brought up to date, then a clone of it.
interface BuildCheckout
{
    /**
     * @throws BuildCheckoutFailed
     */
    public function updateMirror(string $project, string $remote): void;

    /**
     * @throws BuildCheckoutFailed
     */
    public function makeClone(string $project, string $branch, string $directory): void;
}
