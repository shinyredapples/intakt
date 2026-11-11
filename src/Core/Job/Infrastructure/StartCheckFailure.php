<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

final readonly class StartCheckFailure
{
    public function __construct(
        public StartCheck $check,
        public string $reason,
    ) {
    }
}
