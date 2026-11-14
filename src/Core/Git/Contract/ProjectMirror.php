<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use InvalidArgumentException;

interface ProjectMirror
{
    /**
     * @throws InvalidArgumentException
     * @throws NetworkGitFailed
     * @throws MirrorNotUpdated
     */
    public function update(string $project, string $remote): string;
}
