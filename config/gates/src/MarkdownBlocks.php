<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownBlocks
{
    // Matched before trim(), because CommonMark reads four spaces of indentation as code and a backtick in the info string as inline code.
    private const string OPENING_FENCE = '/^ {0,3}(`{3,}(?=[^`]*$)|~{3,})/';

    // CommonMark: a closing fence carries no info string, so a fence line with trailing text stays inside the block.
    private const string CLOSING_FENCE = '/^ {0,3}(`{3,}|~{3,})\s*$/';

    // CommonMark: an HTML block opens with <!-- after at most three spaces, so a deeper indented opener is code and opens none.
    private const string COMMENT_OPENER = '/^ {0,3}<!--/';

    private const string COMMENT = '<!--';

    private const string COMMENT_CLOSER = '-->';

    /**
     * @param list<string> $lines
     *
     * @return list<MarkdownBlock>
     */
    public function of(array $lines): array
    {
        $blocks = [];
        $open = null;

        // One state for both kinds: whichever block a line opens owns the lines up to its own closer, so a fence inside a comment opens nothing, and a comment opener inside a fence opens nothing.
        foreach ($lines as $line) {
            $block = $open ?? $this->openedBy($line);
            $blocks[] = $this->kindOf($block);
            $open = null === $open && self::COMMENT !== $block ? $block : $this->after($line, $block);
        }

        return $blocks;
    }

    private function openedBy(string $line): ?string
    {
        if (1 === preg_match(self::COMMENT_OPENER, $line)) {
            return self::COMMENT;
        }

        return 1 === preg_match(self::OPENING_FENCE, $line, $marker) ? $marker[1] : null;
    }

    private function kindOf(?string $block): MarkdownBlock
    {
        return match (true) {
            null === $block => MarkdownBlock::Text,
            self::COMMENT === $block => MarkdownBlock::Comment,
            default => MarkdownBlock::Fence,
        };
    }

    // Only a line after the opening fence can close it, because CommonMark reads the opener's trailing text as the info string, while a one-line comment closes on its own line.
    private function after(string $line, ?string $block): ?string
    {
        if (null === $block) {
            return null;
        }

        if (self::COMMENT === $block) {
            return str_contains($line, self::COMMENT_CLOSER) ? null : $block;
        }

        if (1 !== preg_match(self::CLOSING_FENCE, $line, $marker)) {
            return $block;
        }

        return $this->closes($marker[1], $block) ? null : $block;
    }

    private function closes(string $marker, string $fence): bool
    {
        // CommonMark: a fence closes only on its own character and at least its own length, so a longer block can show a shorter fence.
        return $marker[0] === $fence[0] && strlen($marker) >= strlen($fence);
    }
}
