<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class IndentedCodeBlocks
{
    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public function blanked(array $lines): array
    {
        $canStart = true;
        $inList = false;
        $inCode = false;

        foreach ($lines as $index => $line) {
            // CommonMark: indented code cannot interrupt a paragraph, and inside a list item the indentation continues the item.
            $inCode = $inCode ? $this->continuesCode($line) : $canStart && !$inList && $this->startsCode($line);

            if ($inCode) {
                $lines[$index] = '';
                continue;
            }

            $inList = $this->inListAfter($line, $inList, $canStart);
            $canStart = '' === trim($line) || 1 === preg_match(MarkdownPage::HEADING, $line);
        }

        return $lines;
    }

    private function continuesCode(string $line): bool
    {
        return '' === trim($line) || $this->indentOf($line) >= 4;
    }

    private function startsCode(string $line): bool
    {
        return '' !== trim($line) && $this->indentOf($line) >= 4;
    }

    private function inListAfter(string $line, bool $inList, bool $canStart): bool
    {
        if (1 === preg_match(MarkdownPage::LIST_ITEM, $line)) {
            return true;
        }

        return $inList && !($canStart && '' !== trim($line) && 0 === $this->indentOf($line));
    }

    private function indentOf(string $line): int
    {
        return strlen(str_replace("\t", '    ', substr($line, 0, strspn($line, " \t"))));
    }
}
