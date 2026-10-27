<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class Change
{
    public function __construct(
        public ChangeId $id,
        public CommitRange $commits,
        public Diff $diff,
    ) {
    }
}
