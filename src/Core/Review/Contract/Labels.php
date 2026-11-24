<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The labels of a change as one value, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class Labels implements IteratorAggregate
{
    /** @var list<string> */
    private array $labels;

    public function __construct(string ...$labels)
    {
        $this->labels = array_values($labels);
    }

    public function contains(string $label): bool
    {
        return in_array($label, $this->labels, true);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->labels);
    }
}
