<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

use DateTimeImmutable;

// One branch on its way through the rule chain: the first rule that decides wins, and later rules pass a decided judgement on unchanged.
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
        return null === $this->verdict ? clone($this, ['verdict' => $verdict]) : $this;
    }

    public function isPast(DateTimeImmutable $moment, int $days): bool
    {
        return $moment->modify(sprintf('+%d days', $days)) <= $this->at;
    }
}
