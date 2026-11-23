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
        private string $lineAbove = '',
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
        $lineAbove = $this->lineAbove;

        while ($nextLine < $position && $nextLine <= strlen($this->text)) {
            $lineEnd = MarkdownText::lineEndAt($this->text, $nextLine);
            $line = MarkdownText::withSpacesForTabs(substr($this->text, $nextLine, $lineEnd - $nextLine));
            $columns = $this->afterLine($columns, $lineAbove, $line);
            $lineAbove = $line;
            $nextLine = $lineEnd + 1;
        }

        return new self($this->text, $columns, $nextLine, $lineAbove);
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

    // A marker on the line after the lines read starts an item unless it continues the paragraph above it, so a fence right after a marker that cannot start an item opens nothing.
    public function letsAnItemStartOn(string $line): bool
    {
        $line = MarkdownText::withSpacesForTabs($line);

        return !$this->continuesTheParagraph($this->withoutEndedItems($this->columns, strspn($line, ' ')), $this->lineAbove, $line);
    }

    // The first line of a fenced block or an HTML comment is read, because a list marker before the part still starts an item; its other lines are not, because what they show or hide is no structure.
    public function passOver(int $start, int $end): self
    {
        $read = $this->readUpTo(MarkdownText::lineStartAt($this->text, $start) + 1);

        // A fence over several lines is a block of its own, and so is an HTML comment whose `<!--` opens its line, up to three columns in, together with the text after its `-->` (https://spec.commonmark.org/0.31.2/#html-blocks, kind 2). So the line after a block continues no paragraph, while a comment indented further continues the paragraph above it.
        $isABlock = str_starts_with(substr($this->text, $start, 4), '<!--') ? $read->opensAnHtmlBlock($start) : str_contains(substr($this->text, $start, $end - $start), "\n");

        return new self($this->text, $read->columns, max($read->nextLine, MarkdownText::lineEndAt($this->text, $end) + 1), $isABlock ? '' : $read->lineAbove);
    }

    public function opensAnHtmlBlock(int $start): bool
    {
        $lineStart = MarkdownText::lineStartAt($this->text, $start);
        $indent = MarkdownText::withSpacesForTabs(substr($this->text, $lineStart, $start - $lineStart));

        return '' === trim($indent) && strlen($indent) <= ($this->columnFor(strlen($indent)) ?? 0) + self::MOST_INDENT;
    }

    // A blank line ends nothing, and a marker starts an item only up to three columns past the content of the item it is in (https://spec.commonmark.org/0.31.2/#list-items). Outside a list, an item that cannot interrupt a paragraph (an empty marker line, an ordered item not numbered 1) continues the paragraph above it.
    /**
     * @param list<int> $columns
     *
     * @return list<int>
     */
    private function afterLine(array $columns, string $lineAbove, string $line): array
    {
        if ('' === trim($line)) {
            return $columns;
        }

        $indent = strspn($line, ' ');
        $columns = $this->withoutEndedItems($columns, $indent);
        $column = MarkdownText::listItemColumnOf($line);

        if (null !== $column && !$this->continuesTheParagraph($columns, $lineAbove, $line) && $indent <= (array_last($columns) ?? 0) + self::MOST_INDENT) {
            $columns[] = $column;
        }

        return $columns;
    }

    /**
     * @param list<int> $columns
     *
     * @return list<int>
     */
    private function withoutEndedItems(array $columns, int $indent): array
    {
        while ([] !== $columns && array_last($columns) > $indent) {
            array_pop($columns);
        }

        return $columns;
    }

    /**
     * @param list<int> $columns
     */
    private function continuesTheParagraph(array $columns, string $lineAbove, string $line): bool
    {
        return [] === $columns && MarkdownText::continuesAParagraph($lineAbove, $line);
    }
}
