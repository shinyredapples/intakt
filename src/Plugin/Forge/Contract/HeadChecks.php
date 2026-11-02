<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

interface HeadChecks
{
    // Only runs and suites of this exact sha count: a rerun or a newer push must not decide for an older head.
    /**
     * @throws ForgeReadFailed
     */
    public function ciStateOf(string $project, CommitId $head): CiState;
}
