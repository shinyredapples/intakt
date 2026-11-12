<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobId;

final readonly class JobContainer
{
    public function __construct(
        public string $id,
        public JobId $job,
    ) {
    }
}
