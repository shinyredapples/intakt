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
        $position = new MarkdownFencePosition([], null, MarkdownLineKind::Blank);

        foreach ($lines as $index => $line) {
            $position = $this->after($line, $position);
            $lines[$index] = MarkdownLineKind::Code === $position->kind ? '' : $line;
        }

        return $lines;
    }

    private function after(string $line, MarkdownFencePosition $before): MarkdownFencePosition
    {
        $open = '' === trim($line) ? $before->lists : $this->indents->within($before->lists, $this->indents->indent($line));

        // CommonMark ends an item at a line indented less than its content, so a fence the item holds ends there too.
        if (null !== $before->fence && count($open) === count($before->lists)) {
            return new MarkdownFencePosition($open, $this->fences->fenceAfter($this->indents->outdented($line, $this->indents->innermost($open)), $before->fence), MarkdownLineKind::Code);
        }

        return $this->startsAListItem($line, $before) ? $this->item($line, $open) : $this->opening($line, $before, $open);
    }

    private function startsAListItem(string $line, MarkdownFencePosition $before): bool
    {
        return 0 === preg_match(MarkdownLineKinds::THEMATIC_BREAK, $line) && $this->indents->startsItem($line, $before->kind, $before->lists);
    }

    /**
     * @param list<int> $open
     */
    private function item(string $line, array $open): MarkdownFencePosition
    {
        // A fence may open on the marker line itself, so the item's content is read without its marker.
        $fence = $this->fences->fenceAfter($this->indents->afterMarker($line), null);
        $kind = null !== $fence ? MarkdownLineKind::Code : MarkdownLineKind::ListItem;

        return new MarkdownFencePosition([...$open, $this->indents->contentIndent($line)], $fence, $this->indents->opensWithCode($line) ? MarkdownLineKind::CodeItem : $kind);
    }

    /**
     * @param list<int> $open
     */
    private function opening(string $line, MarkdownFencePosition $before, array $open): MarkdownFencePosition
    {
        $fence = $this->fences->fenceAfter($this->indents->outdented($line, $this->indents->innermost($open)), null);

        if (null !== $fence) {
            return new MarkdownFencePosition($open, $fence, MarkdownLineKind::Code);
        }

        $kind = new MarkdownTextLines($this->fences, $this->indents)->kindOf($line, $before->kind, $open);
        // A line that continues a paragraph is lazy in CommonMark, so its indentation closes no list item.
        $continues = $before->kind->isText() && $kind->isText();

        return new MarkdownFencePosition($continues ? $before->lists : $open, null, $kind);
    }
}
