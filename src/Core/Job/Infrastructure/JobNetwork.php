<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobId;

final readonly class JobNetwork
{
    public function __construct(
        public string $name,
        public JobId $job,
    ) {
    }
}
