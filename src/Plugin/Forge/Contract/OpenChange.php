<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use DateTimeImmutable;

final readonly class OpenChange
{
    public function __construct(
        public ChangeId $id,
        public CommitId $head,
        public DateTimeImmutable $updatedAt,
    ) {
    }
}
