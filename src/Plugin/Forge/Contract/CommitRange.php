<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class CommitRange
{
    public function __construct(
        public CommitId $base,
        public CommitId $head,
    ) {
    }
}
