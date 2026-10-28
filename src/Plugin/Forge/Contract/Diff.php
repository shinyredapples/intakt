<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class Diff
{
    public function __construct(
        public string $patch,
        public ChangedFiles $files,
    ) {
    }
}
