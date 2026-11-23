<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\MarkReplay;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;

// No lock, because a second flag at the same moment meets the project's primary key, which this handler takes as the project being a replay already.
#[AsCommandHandler]
final readonly class FlagProjectAsReplayHandler
{
    public function __construct(private EntityManagerInterface $entities)
    {
    }

    public function __invoke(FlagProjectAsReplay $command): void
    {
        try {
            // Inserted without a lookup first, because another flag can store the row between the two; in a savepoint, because PostgreSQL refuses every further statement of a transaction in which one failed; past the ORM, because a refused flush closes the entity manager.
            $this->entities->getConnection()->transactional(static fn (Connection $inner): int|string => $inner->insert('review_replay_project', ['project' => $command->project]));
        } catch (UniqueConstraintViolationException) {
            // Ignored, because then the project is flagged already and stays a replay.
        }
    }
}
