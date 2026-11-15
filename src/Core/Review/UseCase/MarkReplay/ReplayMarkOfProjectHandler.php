<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\MarkReplay;

use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Review\Contract\ReplayMark;
use Intakt\Core\Review\Contract\ReplayMarkOfProject;
use Intakt\Core\Review\Domain\ReplayProject;

#[AsQueryHandler]
final readonly class ReplayMarkOfProjectHandler
{
    public function __construct(private EntityManagerInterface $entities)
    {
    }

    public function __invoke(ReplayMarkOfProject $query): ReplayMark
    {
        return $this->entities->find(ReplayProject::class, $query->project) instanceof ReplayProject ? ReplayMark::Replay : ReplayMark::Live;
    }
}
