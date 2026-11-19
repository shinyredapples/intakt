<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

// The list item a line belongs to depends on every line above it: a less indented line that is no item ends the deeper items, and a marker shown inside a fenced block or hidden in an HTML comment starts none. So the text is read once from the top, each line at most once, and the reader of the whole text (`MarkdownText`) tells it which parts to pass over (`passOver()`), instead of walking up from every fence.
final class OpenListItems
{
    private const int MOST_INDENT = 3;

    // The content columns of the open items, the innermost last; each is right of the one before.
    /**
     * @var list<int>
     */
    private array $columns = [];

    private int $nextLine = 0;

    public function __construct(
        private readonly string $text,
    ) {
    }

    // The line belongs to the innermost open item whose content column its indent (in columns) reaches.
    public function columnFor(int $lineStart, int $indent): ?int
    {
        $this->readUpTo($lineStart);
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
    public function passOver(int $start, int $end): void
    {
        $this->readUpTo(MarkdownText::lineStartAt($this->text, $start) + 1);
        $this->nextLine = max($this->nextLine, MarkdownText::lineEndAt($this->text, $end) + 1);
    }

    private function readUpTo(int $position): void
    {
        while ($this->nextLine < $position && $this->nextLine <= strlen($this->text)) {
            $lineEnd = MarkdownText::lineEndAt($this->text, $this->nextLine);
            $this->read(MarkdownText::withSpacesForTabs(substr($this->text, $this->nextLine, $lineEnd - $this->nextLine)));
            $this->nextLine = $lineEnd + 1;
        }
    }

    // A blank line ends nothing. Any other line ends the items whose content it does not reach, and a marker indented at most three columns past the content of the item it is in starts an item (https://spec.commonmark.org/0.31.2/#list-items).
    private function read(string $line): void
    {
        if ('' === trim($line)) {
            return;
        }

        $indent = strspn($line, ' ');

        while ([] !== $this->columns && $this->innermost() > $indent) {
            array_pop($this->columns);
        }

        $column = MarkdownText::listItemColumnOf($line);

        if (null !== $column && $indent <= $this->innermost() + self::MOST_INDENT) {
            $this->columns[] = $column;
        }
    }

    private function innermost(): int
    {
        return array_last($this->columns) ?? 0;
    }
}
