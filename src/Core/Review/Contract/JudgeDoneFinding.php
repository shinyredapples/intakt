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
        // Null by default, because a judge_done row written before the place was recorded holds neither.
        public ?string $file = null,
        public ?int $line = null,
        // Null by default, because a judge_done row written before the judge's note was recorded holds none, and so does every finding the judge did not defer or reject as a P1 or P2.
        public ?string $judgeNote = null,
        // The severity the finding's reviewer gave it, because an act entry is recorded with the highest severity of the findings it merged; null by default, because a judge_done row written before it was recorded holds none.
        public ?Severity $reportedSeverity = null,
    ) {
    }
}
