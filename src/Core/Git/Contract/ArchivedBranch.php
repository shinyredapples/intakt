<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use DateTimeImmutable;

final readonly class ArchivedBranch
{
    public function __construct(
        public string $ref,
        public BranchHead $head,
        public DateTimeImmutable $archivedAt,
    ) {
    }
}
