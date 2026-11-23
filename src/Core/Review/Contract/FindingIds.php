<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use ArrayIterator;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

// Finding ids as one value, because module contracts carry typed objects and never lists of strings; encoded as a JSON list, so the MCP answers that json_encode findings keep their shape.
/**
 * @implements IteratorAggregate<int, string>
 */
final readonly class FindingIds implements IteratorAggregate, JsonSerializable
{
    /** @var list<string> */
    private array $ids;

    public function __construct(string ...$ids)
    {
        $this->ids = array_values($ids);
    }

    public function contains(string $id): bool
    {
        return in_array($id, $this->ids, true);
    }

    /**
     * @return Traversable<int, string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->ids);
    }

    // The JSON list of ids; typed mixed, because JsonSerializable names no list type and a contract signature holds no array of scalars.
    public function jsonSerialize(): mixed
    {
        return $this->ids;
    }
}
