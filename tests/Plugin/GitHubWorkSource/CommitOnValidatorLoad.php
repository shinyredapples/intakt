<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Closure;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Intakt\Plugin\GitHubWorkSource\Domain\IntakeValidator;

// Listens to `postLoad` because the pick reads the sync state row and the failures in one call: this forces another worker's commit in after that row was read and before the failures are.
final class CommitOnValidatorLoad
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
        if ($this->done || !$event->getObject() instanceof IntakeValidator) {
            return;
        }

        $this->done = true;
        ($this->commit)();
    }
}
