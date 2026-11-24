<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

final readonly class JobOutputFile
{
    public function __construct(
        public string $name,
        public string $contents,
    ) {
    }
}
