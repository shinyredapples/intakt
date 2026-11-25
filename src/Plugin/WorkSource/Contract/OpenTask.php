<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

use DateTimeImmutable;

final readonly class OpenTask
{
    /**
     * @param list<Wait> $waits
     */
    public function __construct(
        public int $number,
        public string $title,
        public TaskPriority $priority,
        public ?DateTimeImmutable $milestoneDue,
        public Holder $holder,
        public array $waits,
    ) {
    }
}
