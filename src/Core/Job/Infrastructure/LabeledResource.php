<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

final readonly class LabeledResource
{
    public function __construct(
        public string $id,
        public string $jobId,
    ) {
    }
}
