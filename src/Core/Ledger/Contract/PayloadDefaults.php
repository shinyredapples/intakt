<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// Named by the payload's constructor parameters, so a row written before a parameter existed is still read.
/**
 * @implements IteratorAggregate<string, mixed>
 */
final readonly class PayloadDefaults implements IteratorAggregate
{
    /** @var array<string, mixed> */
    private array $values;

    public function __construct(mixed ...$values)
    {
        $this->values = $values;
    }

    /**
     * @return Traversable<string, mixed>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }
}
