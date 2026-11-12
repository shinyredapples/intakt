<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Contract;

interface ProjectModes
{
    public function of(string $project): ProjectMode;
}
