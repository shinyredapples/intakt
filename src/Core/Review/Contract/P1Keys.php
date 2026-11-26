<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use IteratorAggregate;
use Traversable;

// Each P1 finding's key by finding id as one value, because module contracts carry typed objects and never maps of strings; null for a finding that gets no key.
/**
 * @implements IteratorAggregate<string, ?string>
 */
final readonly class P1Keys implements IteratorAggregate
{
    /** @var array<array-key, ?string> */
    private array $keys;

    public function __construct()
    {
        // Typed as the property, because with() fills it through clone() and static analysis would read it as always empty.
        /** @var array<array-key, ?string> $none */
        $none = [];
        $this->keys = $none;
    }

    public function with(string $finding, ?string $key): self
    {
        $keys = $this->keys;
        $keys[$finding] = $key;

        return clone ($this, ['keys' => $keys]);
    }

    public function keyOf(string $finding): ?string
    {
        return $this->keys[$finding] ?? null;
    }

    /**
     * @return Traversable<string, ?string>
     */
    public function getIterator(): Traversable
    {
        // Cast back, because PHP turns a numeric string key into an int.
        foreach ($this->keys as $finding => $key) {
            yield (string) $finding => $key;
        }
    }
}
