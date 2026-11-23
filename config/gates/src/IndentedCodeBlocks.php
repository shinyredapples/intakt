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
        $context = IndentedContext::Text;
        $previous = '';

        foreach ($lines as $index => $line) {
            $context = $this->contextOf($line, $context, $previous);
            $previous = IndentedContext::Code === $context ? '' : $line;

            if (IndentedContext::Code === $context) {
                $lines[$index] = '';
            }
        }

        return $lines;
    }

    // CommonMark: indented code cannot interrupt a paragraph, and inside a list item the indentation continues the item.
    private function contextOf(string $line, IndentedContext $context, string $previous): IndentedContext
    {
        if (IndentedContext::Code === $context && ('' === trim($line) || $this->indentOf($line) >= 4)) {
            return IndentedContext::Code;
        }

        if (IndentedContext::ListItem !== $context && $this->opensBlock($previous) && '' !== trim($line) && $this->indentOf($line) >= 4) {
            return IndentedContext::Code;
        }

        return $this->listContextOf($line, $context, $previous);
    }

    private function listContextOf(string $line, IndentedContext $context, string $previous): IndentedContext
    {
        if (1 === preg_match(MarkdownPage::LIST_ITEM, $line)) {
            return IndentedContext::ListItem;
        }

        $leavesList = $this->opensBlock($previous) && '' !== trim($line) && 0 === $this->indentOf($line);

        return IndentedContext::ListItem === $context && !$leavesList ? IndentedContext::ListItem : IndentedContext::Text;
    }

    private function opensBlock(string $previous): bool
    {
        return '' === trim($previous) || 1 === preg_match(MarkdownPage::HEADING, $previous);
    }

    private function indentOf(string $line): int
    {
        return strlen(str_replace("\t", '    ', substr($line, 0, strspn($line, " \t"))));
    }
}
