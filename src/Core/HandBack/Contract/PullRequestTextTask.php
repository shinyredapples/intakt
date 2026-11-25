<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

// A task is its resolved repository path and its branch, as in the hand-back round, so one task's lines match however its path was written.
final readonly class PullRequestTextTask
{
    private function __construct(
        public string $repository,
        public string $branch,
        public string $key,
    ) {
    }

    /**
     * @throws PullRequestTextTaskRefused
     */
    public static function resolved(string $repository, string $branch): self
    {
        // Refused when empty, because realpath() resolves an empty path to the working folder.
        $path = '' === $repository ? false : realpath($repository);

        if (false === $path) {
            throw new PullRequestTextTaskRefused(sprintf('the repository "%s" cannot be found', $repository));
        }

        if ('' === trim($branch)) {
            throw new PullRequestTextTaskRefused('the branch is empty');
        }

        // Length-prefixed parts, because a path or a branch may hold any separator, and plain bytes, because Git takes a branch name that is not UTF-8.
        return new self($path, $branch, hash('sha256', strlen($path).':'.$path.strlen($branch).':'.$branch));
    }
}
