<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class Change
{
    /**
     * @param list<ChangedFile> $files
     */
    public function __construct(
        public ChangeId $id,
        public CommitId $base,
        public CommitId $head,
        public string $diff,
        public array $files,
    ) {
    }
}
