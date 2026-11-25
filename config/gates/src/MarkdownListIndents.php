<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownListIndents
{
    private const string LIST_MARKER = '/^( *)([-*+]|\d{1,9}[.)])([ \t]*)/';

    // Because CommonMark 5.2 lets only an item with text, numbered 1 when ordered, interrupt a paragraph, other markers continue it.
    private const string INTERRUPTING_ITEM = '/^[ \t]*(?:[-*+]|0{0,8}1[.)])[ \t]+\S/';

    /**
     * @param list<int> $lists
     */
    public function startsItem(string $line, MarkdownLineKind $previous, array $lists): bool
    {
        if (1 !== preg_match(MarkdownLineKinds::LIST_ITEM, $line)) {
            return false;
        }

        // A marker left of the open item's content is the next item of a list, so it interrupts no paragraph.
        return !$previous->isText()
            || $this->indent($line) < $this->innermost($lists)
            || $this->interruptsAParagraph($line, $lists);
    }

    public function indent(string $line): int
    {
        $expanded = $this->expanded($line);

        return strlen($expanded) - strlen(ltrim($expanded, ' '));
    }

    public function contentIndent(string $listItem): int
    {
        preg_match(self::LIST_MARKER, $this->expanded($listItem), $parts);
        $gap = strlen($parts[3] ?? '');

        // A gap of five or more columns starts indented code in the item, so its content starts one column after the marker (CommonMark 5.2).
        return strlen($parts[1] ?? '') + strlen($parts[2] ?? '') + (0 === $gap || $gap > 4 ? 1 : $gap);
    }

    public function afterMarker(string $listItem): string
    {
        return substr($this->expanded($listItem), $this->contentIndent($listItem));
    }

    public function opensWithCode(string $listItem): bool
    {
        preg_match(self::LIST_MARKER, $this->expanded($listItem), $parts);

        // CommonMark 5.2 reads a gap of five or more columns after the marker as indented code, so such a first line is no prose.
        return strlen($parts[3] ?? '') > 4 && '' !== trim($this->afterMarker($listItem));
    }

    /**
     * @param list<int> $lists
     *
     * @return list<int>
     */
    public function within(array $lists, int $column): array
    {
        return array_values(array_filter($lists, static fn (int $content): bool => $content <= $column));
    }

    /**
     * @param list<int> $lists
     */
    public function innermost(array $lists): int
    {
        return [] === $lists ? 0 : array_last($lists);
    }

    public function outdented(string $line, int $columns): string
    {
        return substr($this->expanded($line), min($columns, $this->indent($line)));
    }

    /**
     * @param list<int> $lists
     */
    private function interruptsAParagraph(string $line, array $lists): bool
    {
        // Four columns past the open item's content is indented code, which cannot interrupt a paragraph, so the line continues it.
        return $this->indent($line) < $this->innermost($lists) + 4 && 1 === preg_match(self::INTERRUPTING_ITEM, $line);
    }

    private function expanded(string $line): string
    {
        $leading = strspn($line, " \t");
        $columns = '';

        foreach (str_split(substr($line, 0, $leading)) as $character) {
            $columns .= "\t" === $character ? str_repeat(' ', 4 - strlen($columns) % 4) : ' ';
        }

        return $columns.substr($line, $leading);
    }
}
