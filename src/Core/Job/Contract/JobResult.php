<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

final readonly class JobResult
{
    public function __construct(
        public JobEnd $end,
        public JobOutput $output,
    ) {
    }
}
