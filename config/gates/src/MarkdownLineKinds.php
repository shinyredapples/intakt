<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownLineKinds
{
    public const string HEADING = '/^ {0,3}(#{1,6})(?:[ \t]|$)/';

    public const string THEMATIC_BREAK = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/';

    public const string LIST_ITEM = '/^[ \t]*(?:[-*+]|\d{1,9}[.)])(?:[ \t]+|$)/';

    public const string INDENTED_CODE = '/^(?: {4}| {0,3}\t)/';

    public function __construct(private MarkdownListIndents $indents)
    {
    }

    /**
     * @param list<int>        $depths
     * @param list<string>     $contents
     * @param array<int, true> $tableRows
     *
     * @return list<MarkdownLineKind>
     */
    public function of(array $depths, array $contents, array $tableRows): array
    {
        $kinds = [];
        $lists = [];

        foreach ($contents as $index => $content) {
            $previous = $this->previousKind($kinds, $depths, $index);
            $kind = isset($tableRows[$index]) ? MarkdownLineKind::Table : $this->kindOf($content, $previous, $lists);
            $lists = $this->continuesAParagraph($kind, $previous) ? $lists : $this->openLists($kind, $content, $lists);
            $kinds[] = MarkdownLineKind::CodeItem === $kind ? MarkdownLineKind::Code : $kind;
        }

        return $kinds;
    }

    /**
     * @param list<MarkdownLineKind> $kinds
     * @param list<int>              $depths
     */
    private function previousKind(array $kinds, array $depths, int $index): MarkdownLineKind
    {
        $before = $index - 1;

        if (!isset($kinds[$before], $depths[$before], $depths[$index])) {
            return MarkdownLineKind::Blank;
        }

        // A line at another quote depth starts fresh, except that one after quoted prose may lazily continue that paragraph.
        $continues = $depths[$before] === $depths[$index] || ($depths[$index] < $depths[$before] && MarkdownLineKind::Prose === $kinds[$before]);

        return $continues ? $kinds[$before] : MarkdownLineKind::Blank;
    }

    /**
     * @param list<int> $lists
     */
    private function kindOf(string $content, MarkdownLineKind $previous, array $lists): MarkdownLineKind
    {
        return match (true) {
            '' === trim($content) => MarkdownLineKind::Blank,
            $this->startsIndentedCode($content, $previous, $lists) => MarkdownLineKind::Code,
            1 === preg_match(self::HEADING, $content) => MarkdownLineKind::Heading,
            1 === preg_match(self::THEMATIC_BREAK, $content) => MarkdownLineKind::Break,
            $this->indents->startsItem($content, $previous, $lists) => $this->indents->opensWithCode($content) ? MarkdownLineKind::CodeItem : MarkdownLineKind::ListItem,
            default => MarkdownLineKind::Prose,
        };
    }

    /**
     * @param list<int> $lists
     */
    private function startsIndentedCode(string $content, MarkdownLineKind $previous, array $lists): bool
    {
        // CommonMark: indented code cannot interrupt a paragraph, and inside a list item it starts four columns past the item's content.
        return $this->indents->indent($content) >= $this->indents->innermost($lists) + 4
            && !$previous->isText();
    }

    private function continuesAParagraph(MarkdownLineKind $kind, MarkdownLineKind $previous): bool
    {
        // A line that continues a paragraph is lazy in CommonMark, so its indentation closes no list item.
        return MarkdownLineKind::Prose === $kind && MarkdownLineKind::Blank !== $previous;
    }

    /**
     * @param list<int> $lists
     *
     * @return list<int>
     */
    private function openLists(MarkdownLineKind $kind, string $content, array $lists): array
    {
        return match (true) {
            in_array($kind, [MarkdownLineKind::ListItem, MarkdownLineKind::CodeItem], true) => [...$this->indents->within($lists, $this->indents->indent($content)), $this->indents->contentIndent($content)],
            // A heading, break or table indented to an item's content sits inside that item, so it keeps the item open.
            in_array($kind, [MarkdownLineKind::Heading, MarkdownLineKind::Break, MarkdownLineKind::Table, MarkdownLineKind::Prose], true) => $this->indents->within($lists, $this->indents->indent($content)),
            default => $lists,
        };
    }
}
