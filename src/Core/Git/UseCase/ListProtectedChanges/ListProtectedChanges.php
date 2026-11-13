<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\ListProtectedChanges;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Domain\ChangeNotRead;
use Intakt\Core\Git\Domain\ProtectedChanges;
use Intakt\Core\Git\Domain\ProtectedPaths;
use InvalidArgumentException;
use NoDiscard;

final readonly class ListProtectedChanges
{
    public function __construct(
        private ChangedPaths $changed,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[NoDiscard]
    public function of(string $repository, string $head, ProtectedPaths $protected): ProtectedChanges
    {
        $this->changed->mustBeFullCommitName($head);

        try {
            $repository = $this->changed->repositoryAt($repository);
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

        return ProtectedChanges::touching($protected->touchedBy($changes));
    }
}
