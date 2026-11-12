<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

interface ProjectModes
{
    public function of(string $project): ProjectMode;
}
