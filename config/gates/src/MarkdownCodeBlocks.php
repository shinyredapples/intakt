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
        $lists = [];
        $fence = null;

        foreach ($lines as $index => $line) {
            if ('' !== trim($line)) {
                $open = $this->indents->within($lists, $this->indents->indent($line));
                // CommonMark: a line indented less than the item's content ends the item, and with it any fence the item holds.
                $fence = count($open) < count($lists) ? null : $fence;
                $lists = $open;
            }

            if (null !== $fence) {
                $fence = $this->fences->fenceAfter($this->indents->outdented($line, $this->indents->innermost($lists)), $fence);
                $lines[$index] = '';

                continue;
            }

            $content = $this->indents->outdented($line, $this->indents->innermost($lists));

            if ($this->startsAListItem($line)) {
                // A fence may open on the marker line itself, so the item's content is read without its marker.
                $content = $this->indents->afterMarker($line);
                $lists[] = $this->indents->contentIndent($line);
            }

            $fence = $this->fences->fenceAfter($content, null);
            $lines[$index] = null === $fence ? $line : '';
        }

        return $lines;
    }

    private function startsAListItem(string $line): bool
    {
        return 1 === preg_match(MarkdownLineKinds::LIST_ITEM, $line) && 0 === preg_match(MarkdownLineKinds::THEMATIC_BREAK, $line);
    }
}
