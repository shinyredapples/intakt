<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\Domain;

use DateInterval;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Later rules pass a decided judgement on unchanged, so the first rule that decides wins and a keep rule cannot be overruled by a later one.
#[Exclude]
final readonly class Judgement
{
    public function __construct(
        public BranchFacts $facts,
        public DateTimeImmutable $now,
        public ?BranchWarning $warning,
        public ?Verdict $verdict = null,
    ) {
    }

    public function decided(Verdict $verdict): self
    {
        return $this->verdict instanceof Verdict ? $this : clone ($this, ['verdict' => $verdict]);
    }

    public function isPast(DateTimeImmutable $moment, int $days): bool
    {
        return $moment->add(new DateInterval(sprintf('P%dD', $days))) <= $this->now;
    }

    // Due by time still needs the week's warning for this branch at its current head.
    public function decidedDue(): self
    {
        $branch = $this->facts->branch;
        $warned = $this->warning?->allowsDeletionAt($branch->name, $branch->head, $this->now) ?? false;

        return $this->decided($warned ? Verdict::Deletable : Verdict::Warn);
    }
}
