<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

use DateTimeImmutable;

// Later rules pass a decided judgement on unchanged, so the first rule that decides wins and a keep rule cannot be overruled by a later one.
final readonly class Judgement
{
    public function __construct(
        public BranchFacts $facts,
        public DateTimeImmutable $at,
        public ?Verdict $verdict = null,
    ) {
    }

    public function decided(Verdict $verdict): self
    {
        return $this->verdict instanceof Verdict ? $this : clone ($this, ['verdict' => $verdict]);
    }

    public function isPast(DateTimeImmutable $moment, int $days): bool
    {
        return $moment->modify(sprintf('+%d days', $days)) <= $this->at;
    }
}
