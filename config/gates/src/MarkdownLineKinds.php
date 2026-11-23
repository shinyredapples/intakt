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
            $kind = match (true) {
                '' === trim($content) => MarkdownLineKind::Blank,
                isset($tableRows[$index]) => MarkdownLineKind::Table,
                $this->startsIndentedCode($content, $previous, $lists) => MarkdownLineKind::Code,
                1 === preg_match(self::HEADING, $content) => MarkdownLineKind::Heading,
                1 === preg_match(self::THEMATIC_BREAK, $content) => MarkdownLineKind::Break,
                1 === preg_match(self::LIST_ITEM, $content) => MarkdownLineKind::ListItem,
                default => MarkdownLineKind::Prose,
            };
            $lists = $this->openLists($kind, $previous, $content, $lists);
            $kinds[] = $kind;
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

        // A line at another quote depth cannot continue the block above it, so it starts fresh as after a blank line.
        if (!isset($kinds[$before], $depths[$before], $depths[$index]) || $depths[$before] !== $depths[$index]) {
            return MarkdownLineKind::Blank;
        }

        return $kinds[$before];
    }

    /**
     * @param list<int> $lists
     */
    private function startsIndentedCode(string $content, MarkdownLineKind $previous, array $lists): bool
    {
        // CommonMark: indented code cannot interrupt a paragraph, and inside a list item it starts four columns past the item's content.
        return $this->indents->indent($content) >= $this->indents->innermost($lists) + 4
            && !in_array($previous, [MarkdownLineKind::Prose, MarkdownLineKind::ListItem], true);
    }

    /**
     * @param list<int> $lists
     *
     * @return list<int>
     */
    private function openLists(MarkdownLineKind $kind, MarkdownLineKind $previous, string $content, array $lists): array
    {
        return match ($kind) {
            MarkdownLineKind::ListItem => [...$this->indents->within($lists, $this->indents->indent($content)), $this->indents->contentIndent($content)],
            MarkdownLineKind::Heading, MarkdownLineKind::Break, MarkdownLineKind::Table => [],
            MarkdownLineKind::Prose => MarkdownLineKind::Blank === $previous ? $this->indents->within($lists, $this->indents->indent($content)) : $lists,
            default => $lists,
        };
    }
}
