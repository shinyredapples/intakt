<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use InvalidArgumentException;

interface BuildClones
{
    /**
     * @throws InvalidArgumentException
     * @throws BuildCloneNotMade
     * @throws RepositoryAttributesRefused
     */
    public function make(string $project, string $branch, string $directory): void;
}
