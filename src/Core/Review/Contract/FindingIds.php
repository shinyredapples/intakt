<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

// Finding ids as one value, because module contracts carry typed objects and never lists of strings; encoded as a JSON list, so the MCP answers that json_encode findings keep their shape.
/**
 * @implements IteratorAggregate<array-key, string>
 */
final readonly class FindingIds implements IteratorAggregate, Countable, JsonSerializable
{
    /** @var array<array-key, string> */
    private array $ids;

    public function __construct(string ...$ids)
    {
        $this->ids = array_values($ids);
    }

    // The key is kept, because a row written with a keyed list must be written back unchanged.
    public function with(int|string $key, string $id): self
    {
        $ids = $this->ids;
        $ids[$key] = $id;

        return clone ($this, ['ids' => $ids]);
    }

    public function contains(string $id): bool
    {
        return in_array($id, $this->ids, true);
    }

    /**
     * @return Traversable<array-key, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->ids);
    }

    public function count(): int
    {
        return count($this->ids);
    }

    public function jsonSerialize(): mixed
    {
        return $this->ids;
    }
}
