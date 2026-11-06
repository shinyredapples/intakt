<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class JudgeDoneFinding
{
    public function __construct(
        public string $id,
        public Severity $severity,
        public string $text,
    ) {
    }
}
