<?php

declare(strict_types=1);

namespace Intakt\Core\Shadow\Contract;

use DateTimeImmutable;

final readonly class WeeklyReport
{
    /**
     * @param list<PublishedHead> $heads
     */
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $until,
        public array $heads,
    ) {
    }

    public function withAgreement(Agreement $agreement): int
    {
        return count(array_filter($this->heads, static fn (PublishedHead $head): bool => $head->agreement === $agreement));
    }

    public function matched(): int
    {
        return array_sum(array_map(static fn (PublishedHead $head): int => $head->matched, $this->heads));
    }

    public function loopOnly(): int
    {
        return array_sum(array_map(static fn (PublishedHead $head): int => count($head->loopOnly), $this->heads));
    }

    public function intaktOnly(): int
    {
        return array_sum(array_map(static fn (PublishedHead $head): int => count($head->intaktOnly), $this->heads));
    }
}
