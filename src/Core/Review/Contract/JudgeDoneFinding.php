<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class JudgeDoneFinding
{
    /**
     * @param list<string> $merged
     */
    public function __construct(
        public string $id,
        public Severity $severity,
        public string $text,
        // Empty by default, because a judge_done row written before act entries were recorded holds no merged ids.
        public array $merged = [],
    ) {
    }
}
