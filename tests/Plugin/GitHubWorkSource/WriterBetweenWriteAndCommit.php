<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubWorkSource;

use Doctrine\DBAL\Connection;

// An `onFlush` listener that forces the order of an overlap: on its own connection another worker has written the sync state of project `alpha` and has not committed yet when the flush of the store under test goes on.
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
