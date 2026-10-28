<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class ChangedFile
{
    public function __construct(
        public string $path,
        public FileStatus $status,
    ) {
    }
}
