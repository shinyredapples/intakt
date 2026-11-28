<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Excluded because the round ledger and the stored serializer build it, not the container.
#[Exclude]
final readonly class JudgeDoneFinding
{
    public function __construct(
        public string $id,
        public Severity $severity,
        public string $text,
        // Empty by default, because a judge_done row written before act entries were recorded holds no merged ids.
        public FindingIds $merged = new FindingIds(),
        // Null by default, because a judge_done row written before the place was recorded holds neither.
        public ?string $file = null,
        public ?int $line = null,
        // Null by default, because a judge_done row written before the judge's note was recorded holds none, and so does every finding the judge did not defer or reject as a P1 or P2.
        public ?string $judgeNote = null,
        // False by default, because a judge_done row written before the gate-gap marker was recorded holds none.
        public bool $gateGap = false,
        // Null by default, because a judge_done row written before titles and kinds were recorded holds neither, and those rows are never back-filled.
        public ?string $title = null,
        public ?FindingKind $kind = null,
    ) {
    }
}
