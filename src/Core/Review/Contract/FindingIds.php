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

    /** @var array<int|string, string> */
    private array $written;

    public function __construct(string ...$ids)
    {
        $this->ids = array_values($ids);
        $this->written = $this->ids;
    }

    // A row stored with keyed ids reads back in order but is written with the keys it had, so it stays byte for byte the same.
    /**
     * @param array<int|string, string> $ids
     */
    public static function storedAs(array $ids): self
    {
        return clone (new self(...array_values($ids)), ['written' => $ids]);
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

    /**
     * @return array<int|string, string>
     */
    public function jsonSerialize(): array
    {
        return $this->written;
    }
}
