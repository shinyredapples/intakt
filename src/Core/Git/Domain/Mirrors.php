<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Domain;

use InvalidArgumentException;

final readonly class Mirrors
{
    private const string SAFE_NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*\z/';

    public function __construct(
        public string $directory,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function pathOf(string $project): string
    {
        // Because the name becomes a path segment: a separator or a leading dot would leave the mirrors directory.
        if (1 !== preg_match(self::SAFE_NAME, $project) || str_contains($project, '..')) {
            throw new InvalidArgumentException(sprintf('"%s" is not a project name that can be a folder name', $project));
        }

        return $this->directory.'/'.$project.'.git';
    }
}
