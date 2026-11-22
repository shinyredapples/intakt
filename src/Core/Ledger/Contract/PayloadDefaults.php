<?php

declare(strict_types=1);

namespace Intakt\Core\Ledger\Contract;

use ArrayIterator;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

// Named by the payload's constructor parameters, so a row written before a parameter existed is still read.
/**
 * @implements IteratorAggregate<string, mixed>
 */
final readonly class PayloadDefaults implements IteratorAggregate
{
    /** @var array<string, mixed> */
    private array $values;

    public function __construct(mixed ...$values)
    {
        $named = [];
        foreach ($values as $name => $value) {
            if (!\is_string($name)) {
                throw new InvalidArgumentException('Payload defaults are named by constructor parameter; a positional default would never be read.');
            }

            $named[$name] = $value;
        }

        $this->values = $named;
    }

    /**
     * @return Traversable<string, mixed>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->values);
    }
}
