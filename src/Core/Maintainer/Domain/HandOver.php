<?php

declare(strict_types=1);

namespace Intakt\Core\Maintainer\Domain;

use Intakt\Plugin\WorkSource\Contract\OpenTask;

final readonly class HandOver
{
    public function __construct(
        public OpenTask $task,
        public int $openTasksBehind,
    ) {
    }
}
