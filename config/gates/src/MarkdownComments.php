<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownComments
{
    // A code span comes first so a "<!--" a reader sees in it opens no comment, and it stops only at a blank line or code because CommonMark lets it wrap within its paragraph; an unclosed comment hides the rest only as a block at a line start.
    private const string CODE_SPAN_OR_COMMENT = '/(?<!`)(`+)(?!`)(?:[^\n\0]|\n(?![ \t]*(?:\n|\z)|\0))*?(?<!`)\1(?!`)|<!--(?s:.*?)-->|^ {0,3}<!--(?s:.*)/m';

    private const string CODE_LINE = "\0";

    /**
     * @param list<string> $lines
     * @param list<int>    $codeLines
     *
     * @return array{list<string>, list<int>}
     */
    public function removedFrom(array $lines, array $codeLines): array
    {
        // Code lines are set aside, so a "<!--" a reader sees in code hides nothing after it.
        $masked = array_replace($lines, array_fill_keys($codeLines, self::CODE_LINE));
        $shown = preg_replace_callback(self::CODE_SPAN_OR_COMMENT, $this->blankedComment(...), implode("\n", $masked));

        if (null === $shown) {
            return [$lines, $codeLines];
        }

        $shownLines = explode("\n", $shown);
        $kept = array_values(array_filter($codeLines, static fn (int $index): bool => self::CODE_LINE === $shownLines[$index]));

        return [array_values(array_replace($shownLines, array_intersect_key($lines, array_flip($kept)))), $kept];
    }

    /**
     * @param array<array-key, string> $match
     */
    private function blankedComment(array $match): string
    {
        return str_starts_with(ltrim($match[0]), '<!--') ? ' '.str_repeat("\n", substr_count($match[0], "\n")) : $match[0];
    }
}
