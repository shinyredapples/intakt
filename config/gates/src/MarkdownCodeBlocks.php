<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownCodeBlocks
{
    public function __construct(
        private FencedCodeBlocks $fences,
        private MarkdownListIndents $indents,
    ) {
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    public function blanked(array $lines): array
    {
        $atMargin = $this->fences->blanked($lines);
        // A fence inside a list item may sit four or more columns in, so it is read again with each item's indentation removed.
        $inLists = $this->fences->blanked($this->outdented($lines, $atMargin));

        foreach (array_keys($lines) as $index) {
            if ('' === $atMargin[$index] || '' === $inLists[$index]) {
                $lines[$index] = '';
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $atMargin
     *
     * @return list<string>
     */
    private function outdented(array $lines, array $atMargin): array
    {
        $lists = [];
        $outdented = [];

        foreach ($lines as $index => $line) {
            if ('' !== trim($line)) {
                $lists = $this->indents->within($lists, $this->indents->indent($line));
            }

            $outdented[] = '' === $atMargin[$index] ? '' : $this->indents->outdented($line, $this->indents->innermost($lists));

            if ('' !== $atMargin[$index] && $this->startsAListItem($line)) {
                $lists[] = $this->indents->contentIndent($line);
            }
        }

        return $outdented;
    }

    private function startsAListItem(string $line): bool
    {
        return 1 === preg_match(MarkdownLineKinds::LIST_ITEM, $line) && 0 === preg_match(MarkdownLineKinds::THEMATIC_BREAK, $line);
    }
}
