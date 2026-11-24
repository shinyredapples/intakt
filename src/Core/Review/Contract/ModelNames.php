<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The names of the models a reviewer's calls ran on as one value, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class ModelNames implements IteratorAggregate
{
    /** @var list<string> */
    private array $names;

    public function __construct(string ...$names)
    {
        $this->names = array_values($names);
    }

    public function isEmpty(): bool
    {
        return [] === $this->names;
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->names);
    }
}
