<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// The list item a line belongs to depends on every line above it: a less indented line that is no item ends the deeper items, and a marker shown inside a fenced block or hidden in an HTML comment starts none. So the text is read once from the top, each line at most once, and the reader of the whole text (`MarkdownText`) says which parts to pass over (`passOver()`), instead of walking up from every fence.
final readonly class OpenListItems
{
    private const int MOST_INDENT = 3;

    // The content columns are kept innermost last, because a less indented line ends the innermost items first.
    /**
     * @param list<int> $columns
     */
    private function __construct(
        private string $text,
        private array $columns,
        private int $nextLine,
    ) {
    }

    public static function of(string $text): self
    {
        return new self($text, [], 0);
    }

    // The copy returned goes on from where this one stopped, so each line is read once however often a reader asks.
    public function readUpTo(int $position): self
    {
        $columns = $this->columns;
        $nextLine = $this->nextLine;

        while ($nextLine < $position && $nextLine <= strlen($this->text)) {
            $lineEnd = MarkdownText::lineEndAt($this->text, $nextLine);
            $columns = $this->afterLine($columns, MarkdownText::withSpacesForTabs(substr($this->text, $nextLine, $lineEnd - $nextLine)));
            $nextLine = $lineEnd + 1;
        }

        return new self($this->text, $columns, $nextLine);
    }

    // A deeper item whose content column the indent does not reach has ended at the line, so the line belongs to the innermost item it reaches.
    public function columnFor(int $indent): ?int
    {
        $column = null;

        foreach ($this->columns as $open) {
            if ($open > $indent) {
                break;
            }

            $column = $open;
        }

        return $column;
    }

    // The first line of a fenced block or an HTML comment is read, because a list marker before the part still starts an item; its other lines are not, because what they show or hide is no structure.
    public function passOver(int $start, int $end): self
    {
        $read = $this->readUpTo(MarkdownText::lineStartAt($this->text, $start) + 1);

        return new self($this->text, $read->columns, max($read->nextLine, MarkdownText::lineEndAt($this->text, $end) + 1));
    }

    // A blank line ends nothing, and a marker starts an item only up to three columns past the content of the item it is in (https://spec.commonmark.org/0.31.2/#list-items).
    /**
     * @param list<int> $columns
     *
     * @return list<int>
     */
    private function afterLine(array $columns, string $line): array
    {
        if ('' === trim($line)) {
            return $columns;
        }

        $indent = strspn($line, ' ');

        while ([] !== $columns && array_last($columns) > $indent) {
            array_pop($columns);
        }

        $column = MarkdownText::listItemColumnOf($line);

        if (null !== $column && $indent <= (array_last($columns) ?? 0) + self::MOST_INDENT) {
            $columns[] = $column;
        }

        return $columns;
    }
}
