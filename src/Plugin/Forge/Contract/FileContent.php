<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class FileContent
{
    public function __construct(
        public string $path,
        public CommitId $commit,
        public string $content,
    ) {
    }
}
