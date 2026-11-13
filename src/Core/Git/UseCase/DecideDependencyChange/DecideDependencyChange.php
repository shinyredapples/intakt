<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\DecideDependencyChange;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Core\Git\Domain\ChangeNotRead;
use Intakt\Core\Git\UseCase\ListProtectedChanges\ChangedPaths;
use Intakt\Plugin\Language\Contract\DependencyDecision;
use Intakt\Plugin\Language\Contract\Language;
use InvalidArgumentException;
use NoDiscard;

final readonly class DecideDependencyChange
{
    public function __construct(
        private ChangedPaths $changed,
        private RevisionFiles $files,
        private Language $language,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    #[NoDiscard]
    public function of(string $repository, string $head): DependencyDecision
    {
        $this->changed->mustBeFullCommitName($head);

        try {
            $repository = $this->changed->repositoryAt($repository);
            $main = $this->changed->mainOf($repository);
            $this->changed->mustBeCommit($repository, $head);
            $this->changed->mustBeComplete($repository);
            $decisions = $this->sinceEachMergeBase($repository, $main, $head);
        } catch (ChangeNotRead|RepositoryAttributesRefused $unreadable) {
            return DependencyDecision::unreadable('the change', $unreadable->getMessage());
        }

        // Never routine, because without a base there is nothing the change was judged against.
        if ([] === $decisions) {
            return DependencyDecision::unreadable('the change', 'git named no merge base');
        }

        return DependencyDecision::combined(...$decisions);
    }

    /**
     * @return list<DependencyDecision>
     *
     * @throws ChangeNotRead
     * @throws RepositoryAttributesRefused
     */
    private function sinceEachMergeBase(string $repository, string $main, string $head): array
    {
        $paths = $this->language->dependencyFiles();
        $new = $this->files->at($repository, $head, $paths);
        $decisions = [];

        // From the merge base, never from main's tip, so a branch behind main is asked only about what it adds, not about what main added since.
        foreach ($this->changed->mergeBases($repository, $main, $head) as $base) {
            $decisions[] = $this->language->decideDependencies($this->files->at($repository, $base, $paths), $new);
        }

        return $decisions;
    }
}
