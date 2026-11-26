<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The maintainer's decisions on a task as one value, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class Decisions implements IteratorAggregate
{
    /** @var list<string> */
    private array $decisions;

    public function __construct(string ...$decisions)
    {
        $this->decisions = array_values($decisions);
    }

    public function isEmpty(): bool
    {
        return [] === $this->decisions;
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->decisions);
    }
}
