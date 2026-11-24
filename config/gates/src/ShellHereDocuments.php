<?php

declare(strict_types=1);

namespace Intakt\Gates;

// Because a here-document body starts after the next line feed outside the `$( )` it was opened in, each open substitution owns the end of one queue.
final class ShellHereDocuments
{
    // Because a delimiter word ends at the first unquoted blank or operator, its quoted and escaped parts are read whole, also when their quote is never closed.
    private const string WORD = '/(?:\$\'(?:[^\'\\\\]++|\\\\.)*+\'?|\'[^\']*+\'?|"(?:[^"\\\\]++|\\\\.)*+"?|\\\\.?|[^\'"\\\\$ \t\n;&|()<>]++|\$)++/As';
    // Because bash removes the quotes, the `$` of ANSI-C quoting and the backslashes of the word to get the delimiter, the word is read part by part.
    private const string DELIMITER_PARTS = '/\$\'((?:[^\'\\\\]++|\\\\.)*+)\'?|\'([^\']*+)\'?|"((?:[^"\\\\]++|\\\\.)*+)"?|\\\\(.?)|([^\'"\\\\$]++|\$)/s';
    private const string DOUBLE_QUOTED_ESCAPES = '/\\\\([$`"\\\\\n])/';

    // Because each entry is where the documents of one open `$( )` start in the queue, the count is the number of open substitutions.
    /**
     * @var list<int>
     */
    private array $substitutions = [];

    /**
     * @var list<ShellHereDocument>
     */
    private array $pending = [];

    public function __construct(private readonly string $source)
    {
    }

    public function afterOperator(int $offset): int
    {
        $start = $offset + 2 + strspn($this->source, '-', $offset + 2, 1);
        $stripsTabs = $start > $offset + 2;
        $start += strspn($this->source, " \t", $start);

        if (1 !== preg_match(self::WORD, $this->source, $word, 0, $start)) {
            return $start;
        }

        $delimiter = (string) preg_replace_callback(self::DELIMITER_PARTS, $this->unquoted(...), $word[0]);
        $this->pending[] = new ShellHereDocument($delimiter, false !== strpbrk($word[0], '\'"\\'), $stripsTabs);

        return $start + strlen($word[0]);
    }

    public function openSubstitution(): void
    {
        $this->substitutions[] = count($this->pending);
    }

    // Because the documents of a closed substitution are read after the line of the outer one and already stand at the end of the queue, closing only forgets where they started, so nesting stays linear.
    public function closeSubstitution(): void
    {
        array_pop($this->substitutions);
    }

    public function afterBodies(int $offset): int
    {
        $start = array_last($this->substitutions) ?? 0;

        foreach (array_slice($this->pending, $start) as $document) {
            $offset = $document->afterBody($this->source, $offset);
        }

        // Because only the documents read here leave the queue, they are popped one by one instead of copying the outer ones that stay.
        while (count($this->pending) > $start) {
            array_pop($this->pending);
        }

        return $offset;
    }

    /**
     * @param array<string> $part
     */
    private function unquoted(array $part): string
    {
        return match (true) {
            '' !== ($part[1] ?? '') => stripcslashes($part[1]),
            '' !== ($part[2] ?? '') => $part[2],
            '' !== ($part[3] ?? '') => (string) preg_replace(self::DOUBLE_QUOTED_ESCAPES, '$1', $part[3]),
            '' !== ($part[4] ?? '') => $part[4],
            default => $part[5] ?? '',
        };
    }
}
