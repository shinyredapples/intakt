<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\BootHead;

use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Review\Contract\MainCommit;
use Intakt\Plugin\Forge\Contract\CommitId;
use Intakt\Plugin\LocalGitForge\Contract\LocalRepository;
use Intakt\Plugin\LocalGitForge\Infrastructure\LocalGitForge;
use Intakt\Plugin\LocalGitForge\Infrastructure\NameStatusParser;

#[AsQueryHandler]
final readonly class MainCommitHandler
{
    public function __invoke(MainCommit $query): CommitId
    {
        return new LocalGitForge(LocalRepository::existingAt($query->source->repository, $query->source->base), new NameStatusParser())->baseCommit();
    }
}
