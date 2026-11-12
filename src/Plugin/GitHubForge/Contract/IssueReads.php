<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;

interface IssueReads
{
    /**
     * Every page of the open issues and pull requests, or a failure: a listing that comes back short is never returned.
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function listOpen(string $project, ?string $validator): IssueListing;

    /**
     * The account that last saved the issue's text, which is its author when nobody edited it.
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function lastEditorOf(string $project, int $number): string;
}
