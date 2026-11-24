<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\ListProtectedChanges;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Git\Contract\ProtectedChanges;
use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Domain\ChangeNotRead;
use Intakt\Core\Git\Domain\ProtectedPaths;
use NoDiscard;

final readonly class ListProtectedChanges
{
    // A full object name of either format, because a ref name could move while the check runs, and one that starts with a dash would be an option.
    private const string COMMIT_NAME = '/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/';

    public function __construct(
        private ChangedPaths $changed,
    ) {
    }

    /**
     * @throws CommitNameRefused
     */
    #[NoDiscard]
    public function of(string $repository, string $head, ProtectedPaths $protected): ProtectedChanges
    {
        if (1 !== preg_match(self::COMMIT_NAME, $head)) {
            throw CommitNameRefused::notFull($head);
        }

        try {
            $repository = $this->existing($repository);
            $main = $this->changed->mainOf($repository);
            $this->changed->mustBeCommit($repository, $head);
            $this->changed->mustBeComplete($repository);

            // Both, because a crafted merge can hide a revert or a deletion from a diff against one parent, and a change undone later in the branch leaves no trace in the whole diff.
            $changes = [
                ...$this->changed->sinceMergeBase($repository, $main, $head),
                ...$this->changed->ofEachCommit($repository, $main, $head),
            ];
        } catch (ChangeNotRead|RepositoryAttributesRefused $unreadable) {
            return ProtectedChanges::unreadable($unreadable->getMessage());
        }

        return ProtectedChanges::touching(new Paths(...$protected->touchedBy($changes)));
    }

    /**
     * @throws ChangeNotRead
     */
    private function existing(string $repository): string
    {
        // Because git runs inside the repository, where a relative --git-dir would point below the repository itself, and from a missing folder git would look upward into another one.
        $path = realpath($repository);

        if (false === $path || !is_dir($path)) {
            throw ChangeNotRead::because(sprintf('%s is no folder', $repository));
        }

        return $path;
    }
}
