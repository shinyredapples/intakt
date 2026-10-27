<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, RecordedEvent>
 */
final readonly class RecordedEvents implements IteratorAggregate
{
    /**
     * @param list<RecordedEvent> $events
     */
    public function __construct(
        private array $events,
    ) {
    }

    /**
     * @return Traversable<int, RecordedEvent>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->events);
    }
}
