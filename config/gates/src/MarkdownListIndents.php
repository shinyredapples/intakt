<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownListIndents
{
    private const string LIST_MARKER = '/^( *)([-*+]|\d{1,9}[.)])([ \t]*)/';

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

        // A gap of five or more columns after the marker makes the item's first line indented code (CommonMark 5.2).
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
