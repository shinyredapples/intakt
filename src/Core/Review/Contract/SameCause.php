<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use IteratorAggregate;
use Traversable;

// The judge's links from a finding id to the id of the finding with the same cause as one value, because module contracts carry typed objects and never maps of strings.
/**
 * @implements IteratorAggregate<string, string>
 */
final readonly class SameCause implements IteratorAggregate
{
    /** @var array<string, string> */
    private array $causes;

    public function __construct()
    {
        $this->causes = [];
    }

    public function with(string $finding, string $cause): self
    {
        $causes = $this->causes;
        $causes[$finding] = $cause;

        return clone ($this, ['causes' => $causes]);
    }

    public function causeOf(string $finding): ?string
    {
        return $this->causes[$finding] ?? null;
    }

    /**
     * @return Traversable<string, string>
     */
    public function getIterator(): Traversable
    {
        // Cast back, because PHP turns a numeric string key into an int.
        foreach ($this->causes as $finding => $cause) {
            yield (string) $finding => $cause;
        }
    }
}
