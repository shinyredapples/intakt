<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

// One check for the board and the doctor, so the judgement and its fix text have no second copy (decision D1 of #3826).
interface ForgeConnection
{
    public function run(): ForgeConnectionVerdict;
}
