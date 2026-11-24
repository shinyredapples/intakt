<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a here-document body starts after the next line feed outside the `$( )` it was opened in, the documents wait in one queue per open substitution.
final class ShellHereDocuments
{
    // Because bash removes the quotes, the `$` of ANSI-C quoting and the backslashes of the word to get the delimiter, these are dropped from it.
    private const array QUOTING = ["$'", "'", '"', '\\'];

    private int $substitutions = 0;

    /**
     * @var array<int, list<ShellHereDocument>>
     */
    private array $pending = [];

    public function __construct(private readonly string $source)
    {
    }

    public function queue(string $operator, string $word): void
    {
        $delimiter = str_replace(self::QUOTING, '', $word);
        $this->pending[$this->substitutions][] = new ShellHereDocument($delimiter, $delimiter !== $word, '<<-' === $operator);
    }

    public function openSubstitution(): void
    {
        ++$this->substitutions;
    }

    // Because the documents of a closed substitution are read after the line of the outer one, they join its queue one by one, so many substitutions on one line stay linear.
    public function closeSubstitution(): void
    {
        $inner = $this->pending[$this->substitutions] ?? [];
        unset($this->pending[$this->substitutions]);
        --$this->substitutions;

        foreach ($inner as $document) {
            $this->pending[$this->substitutions][] = $document;
        }
    }

    public function afterBodies(int $offset): int
    {
        foreach ($this->pending[$this->substitutions] ?? [] as $document) {
            $offset = $document->afterBody($this->source, $offset);
        }

        unset($this->pending[$this->substitutions]);

        return $offset;
    }
}
