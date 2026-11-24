<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The models that had no price as one value, because module contracts carry typed objects and never lists of strings; each name is kept once, in the order first met.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class UnpricedModels implements IteratorAggregate
{
    /** @var list<string> */
    private array $names;

    public function __construct(string ...$names)
    {
        $this->names = array_values(array_unique($names));
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
