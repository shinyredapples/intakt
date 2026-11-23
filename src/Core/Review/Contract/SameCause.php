<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

// The judge's links from a finding id to the id of the finding with the same cause as one value, because module contracts carry typed objects and never maps of strings.
/**
 * @implements IteratorAggregate<string, string>
 */
final readonly class SameCause implements IteratorAggregate
{
    /** @var array<array-key, string> */
    private array $causes;

    public function __construct()
    {
        // Typed as the property, because with() fills it through clone() and static analysis would read it as always empty.
        /** @var array<array-key, string> $none */
        $none = [];
        $this->causes = $none;
    }

    // Refused rather than kept, because a link without an id or to the finding itself names no second finding, and the links come from model output and stored rows.
    public function with(string $finding, string $cause): self
    {
        if ('' === $finding || '' === $cause) {
            throw new InvalidArgumentException('A same-cause link needs a finding id and a cause id.');
        }

        if ($finding === $cause) {
            throw new InvalidArgumentException(sprintf('Finding "%s" cannot be its own cause.', $finding));
        }

        return $this->put($finding, $cause);
    }

    // Only for reading a stored row, because rows written before the guard may hold a finding as its own cause and must read back and write back unchanged.
    public function withStored(string $finding, string $cause): self
    {
        if ('' === $finding || '' === $cause) {
            throw new InvalidArgumentException('A same-cause link needs a finding id and a cause id.');
        }

        return $this->put($finding, $cause);
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

    private function put(string $finding, string $cause): self
    {
        $causes = $this->causes;
        $causes[$finding] = $cause;

        return clone ($this, ['causes' => $causes]);
    }
}
