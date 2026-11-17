<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\MarkReplay;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\UnflagReplayProject;
use Intakt\Core\Review\Domain\ReplayProject;

#[AsCommandHandler]
final readonly class UnflagReplayProjectHandler
{
    public function __construct(private EntityManagerInterface $entities)
    {
    }

    public function __invoke(UnflagReplayProject $command): void
    {
        $flag = $this->entities->find(ReplayProject::class, $command->project);

        if (!$flag instanceof ReplayProject) {
            return;
        }

        $this->entities->remove($flag);
        $this->entities->flush();
    }
}
