<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Closure;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Intakt\Plugin\GitHubWorkSource\Domain\TaskFailure;

// Listens to `postLoad` because the store reads the failed-for-good reasons in one call: this forces another worker's commit into the middle of that read, after the rows a plan is answered from were checked.
final class CommitOnFirstFailureLoad
{
    private bool $done = false;

    /**
     * @param Closure(): void $commit
     */
    public function __construct(
        private readonly Closure $commit,
    ) {
    }

    public function postLoad(PostLoadEventArgs $event): void
    {
        if ($this->done || !$event->getObject() instanceof TaskFailure) {
            return;
        }

        $this->done = true;
        ($this->commit)();
    }

    public function committed(): bool
    {
        return $this->done;
    }
}
