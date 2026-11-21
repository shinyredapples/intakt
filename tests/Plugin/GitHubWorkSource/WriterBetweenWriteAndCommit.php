<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Doctrine\DBAL\Connection;

// Listens to `onFlush` because two real workers cannot be made to overlap on demand: this forces the order in which another worker, on its own connection, has written the sync state of project `alpha` and has not committed yet when the store under test writes.
final readonly class WriterBetweenWriteAndCommit
{
    public function __construct(
        private Connection $other,
    ) {
    }

    public function onFlush(): void
    {
        $this->other->beginTransaction();
        $this->other->executeStatement("INSERT INTO work_source_validator (project, validator, generation) VALUES ('alpha', 'winner', 1) ON CONFLICT (project) DO UPDATE SET validator = 'winner', generation = generation + 1");
    }
}
