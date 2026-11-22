<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

// The judge's notes by finding id as one value, because module contracts carry typed objects and never maps of strings.
/**
 * @implements IteratorAggregate<string, string>
 */
final readonly class JudgeNotes implements IteratorAggregate
{
    /** @var array<array-key, string> */
    private array $notes;

    public function __construct()
    {
        // Typed as the property, because with() fills it through clone() and static analysis would read it as always empty.
        /** @var array<array-key, string> $none */
        $none = [];
        $this->notes = $none;
    }

    // Only the id is guarded and the note is kept as given (even empty), because the ledger redacts a note when it stores it and stored rows must read back and write back unchanged.
    public function with(string $finding, string $note): self
    {
        if ('' === $finding) {
            throw new InvalidArgumentException('A judge note needs a finding id.');
        }

        $notes = $this->notes;
        $notes[$finding] = $note;

        return clone ($this, ['notes' => $notes]);
    }

    public function noteOf(string $finding): ?string
    {
        return $this->notes[$finding] ?? null;
    }

    /**
     * @return Traversable<string, string>
     */
    public function getIterator(): Traversable
    {
        // Cast back, because PHP turns a numeric string key into an int.
        foreach ($this->notes as $finding => $note) {
            yield (string) $finding => $note;
        }
    }
}
