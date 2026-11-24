<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownCodeBlocks
{
    // CommonMark: a line starting with "<!--" opens an HTML block up to the line holding "-->", and a fence inside it opens no code.
    private const string COMMENT_BLOCK = '/^ {0,3}<!--/';

    private const string COMMENT = '<!--';

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
            [$lists, $fence, $lines[$index]] = $this->read($line, $lists, $fence);
        }

        return $lines;
    }

    /**
     * @param list<int> $lists
     *
     * @return array{list<int>, ?string, string}
     */
    private function read(string $line, array $lists, ?string $fence): array
    {
        $open = '' === trim($line) ? $lists : $this->indents->within($lists, $this->indents->indent($line));

        // CommonMark ends an item at a line indented less than its content, so a fence the item holds ends there too.
        if (null === $fence || count($open) < count($lists)) {
            return $this->opening($line, $open);
        }

        if (self::COMMENT === $fence) {
            return [$open, str_contains($line, '-->') ? null : self::COMMENT, $line];
        }

        return [$open, $this->fences->fenceAfter($this->indents->outdented($line, $this->indents->innermost($open)), $fence), ''];
    }

    /**
     * @param list<int> $lists
     *
     * @return array{list<int>, ?string, string}
     */
    private function opening(string $line, array $lists): array
    {
        $content = $this->indents->outdented($line, $this->indents->innermost($lists));

        if ($this->startsAListItem($line)) {
            // A fence may open on the marker line itself, so the item's content is read without its marker.
            $content = $this->indents->afterMarker($line);
            $lists[] = $this->indents->contentIndent($line);
        }

        if ($this->opensAComment($content)) {
            return [$lists, self::COMMENT, $line];
        }

        $fence = $this->fences->fenceAfter($content, null);

        return [$lists, $fence, null === $fence ? $line : ''];
    }

    private function opensAComment(string $content): bool
    {
        return 1 === preg_match(self::COMMENT_BLOCK, $content, $opener) && !str_contains(substr($content, strlen($opener[0])), '-->');
    }

    private function startsAListItem(string $line): bool
    {
        return 1 === preg_match(MarkdownLineKinds::LIST_ITEM, $line) && 0 === preg_match(MarkdownLineKinds::THEMATIC_BREAK, $line);
    }
}
