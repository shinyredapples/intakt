<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckPullRequestText;

use InvalidArgumentException;

// A task is its resolved repository path and its branch, as in the hand-back round, so one task's lines match however its path was written.
final readonly class TaskBranch
{
    private function __construct(
        public string $repository,
        public string $branch,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function resolved(string $repository, string $branch): self
    {
        $path = realpath($repository);

        if (false === $path) {
            throw new InvalidArgumentException(sprintf('the repository "%s" cannot be found', $repository));
        }

        if ('' === trim($branch)) {
            throw new InvalidArgumentException('the branch is empty');
        }

        return new self($path, $branch);
    }
}
