<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Contract;

// The one home of the forge judgement, which the board and the doctor both call, so it and its fix text have no second copy (decision D1 of #3826).
interface ForgeConnection
{
    public function run(): ForgeConnectionVerdict;
}
