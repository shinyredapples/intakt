<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Either the whole queue of one tick or nothing, because an order computed over part of the listing would put the wrong task first.
#[Exclude]
final readonly class WorkQueue
{
    /**
     * @param list<Task>     $ready
     * @param list<HeldTask> $held
     */
    private function __construct(
        private array $ready,
        private array $held,
        public bool $complete,
    ) {
    }

    /**
     * @param list<Task>     $ready
     * @param list<HeldTask> $held
     */
    public static function listed(array $ready, array $held): self
    {
        return new self($ready, $held, true);
    }

    public static function incomplete(): self
    {
        return new self([], [], false);
    }

    /**
     * @return list<Task>
     */
    public function ready(): array
    {
        return $this->ready;
    }

    /**
     * @return list<HeldTask>
     */
    public function held(): array
    {
        return $this->held;
    }
}
