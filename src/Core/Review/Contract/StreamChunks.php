<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

// The last chunks a stream showed, each described in one line, as one value, because module contracts carry typed objects and never lists of strings.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class StreamChunks implements IteratorAggregate
{
    /** @var list<string> */
    private array $chunks;

    public function __construct(string ...$chunks)
    {
        $this->chunks = array_values($chunks);
    }

    public function isEmpty(): bool
    {
        return [] === $this->chunks;
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->chunks);
    }
}
