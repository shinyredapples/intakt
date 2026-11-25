<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownTextLines
{
    private const array BLOCKS = [MarkdownLineKinds::HEADING, MarkdownLineKinds::THEMATIC_BREAK, MarkdownPage::FRAMED_LINE];

    public function __construct(
        private FencedCodeBlocks $fences,
        private MarkdownListIndents $indents,
    ) {
    }

    /**
     * @param list<int> $lists
     */
    public function kindOf(string $line, MarkdownLineKind $previous, array $lists): MarkdownLineKind
    {
        $innermost = $this->indents->innermost($lists);

        if (!$previous->isText() && $this->indents->indent($line) >= $innermost + 4) {
            return MarkdownLineKind::Blank;
        }

        $outdented = $this->indents->outdented($line, $innermost);
        $content = $this->unquoted($outdented);

        return match (true) {
            '' === trim($content), null !== $this->fences->fenceAfter($content, null) => MarkdownLineKind::Blank,
            $this->startsABlock($content) => MarkdownLineKind::Break,
            // A quoted marker belongs to a list in the quote, which the lines outside it do not continue.
            $content !== $outdented && $this->indents->startsItem($content, $previous, []) => MarkdownLineKind::Break,
            default => MarkdownLineKind::Prose,
        };
    }

    private function unquoted(string $line): string
    {
        while (1 === preg_match(MarkdownPage::QUOTE_MARKER, $line, $marker)) {
            $line = substr($line, strlen($marker[0]));
        }

        return $line;
    }

    private function startsABlock(string $content): bool
    {
        return array_any(self::BLOCKS, static fn (string $pattern): bool => 1 === preg_match($pattern, $content));
    }
}
