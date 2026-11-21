<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

// The quotes the quote check counted as one value, in the order the check reads them and with repeats kept, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class QuotesCounted implements Countable, IteratorAggregate
{
    /** @var list<string> */
    private array $quotes;

    public function __construct(string ...$quotes)
    {
        $this->quotes = array_values($quotes);
    }

    public function count(): int
    {
        return count($this->quotes);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->quotes);
    }
}
