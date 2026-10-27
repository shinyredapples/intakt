<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class Triage
{
    public function __construct(
        public Severity $severity,
        public FixableBy $fixableBy,
        public bool $repeat,
    ) {
    }
}
