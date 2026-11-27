<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Intakt\Core\HandBack\Contract\RegressionRuns;

// The runs only for a bug whose regression tests ran, so the push can write both results into the pull request.
final readonly class PrePushResult
{
    public function __construct(
        public ?string $failure,
        public ?RegressionRuns $runs,
    ) {
    }
}
