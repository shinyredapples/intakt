<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

final readonly class JobEnd
{
    public function __construct(
        public JobCause $cause,
        public int $exitCode,
    ) {
    }
}
