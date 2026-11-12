<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

use Intakt\Core\Review\Contract\Severity;

final readonly class LoopFinding
{
    public function __construct(
        public string $file,
        public int $line,
        public Severity $severity,
    ) {
    }
}
