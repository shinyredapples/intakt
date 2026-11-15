<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\MarkReplay;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Domain\ReplayProject;

// A second flag at the same moment fails on the project's primary key rather than storing it twice, and the project is a replay either way.
#[AsCommandHandler]
final readonly class FlagProjectAsReplayHandler
{
    public function __construct(private EntityManagerInterface $entities)
    {
    }

    public function __invoke(FlagProjectAsReplay $command): void
    {
        if ($this->entities->find(ReplayProject::class, $command->project) instanceof ReplayProject) {
            return;
        }

        $this->entities->persist(new ReplayProject($command->project));
        $this->entities->flush();
    }
}
