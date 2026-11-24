<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownPage
{
    private const string QUOTE_MARKER = '/^ {0,3}>[ \t]?/';

    // CommonMark: a closing run of hashes counts only after a space, so "In short###" is another heading.
    private const string SUMMARY_HEADING = '/^ {0,3}#{1,6}[ \t]+In short(?:[ \t]+#*)?[ \t]*$/i';

    private const string CELL_BORDER = '/(?<!\\\\)\|/';

    private const string FRAMED_LINE = '/^[ \t]*\|.*(?<!\\\\)\|[ \t]*$/';

    private const string WORD = '/[\p{L}\p{N}]/u';

    private const string HTML_COMMENT = '/<!--.*?(?:-->|\z)/s';

    /** @var list<int> */
    private array $depths;

    /** @var list<string> */
    private array $contents;

    /** @var array<int, MarkdownLineKind> */
    private array $kinds;

    /** @var array<int, true> */
    private array $itemHeadings;

    /**
     * @param list<string> $blankedLines
     * @param list<int>    $fencedLines
     */
    public function __construct(
        public string $path,
        array $blankedLines,
        array $fencedLines = [],
    ) {
        $depths = [];
        $contents = [];

        foreach ($blankedLines as $line) {
            [$depths[], $contents[]] = $this->unquoted($line);
        }

        $this->depths = $depths;
        $this->contents = $contents;
        [$kinds, $this->itemHeadings] = new MarkdownLineKinds(new MarkdownListIndents())->of($depths, $contents, $this->tableRows());

        // A blanked fence still holds content, so a fenced block before "In short" means the summary does not open the page.
        $this->kinds = array_replace($kinds, array_intersect_key(array_fill_keys($fencedLines, MarkdownLineKind::Code), $kinds));
    }

    /**
     * @return list<MarkdownParagraph>
     */
    public function paragraphs(): array
    {
        $paragraphs = [];
        $lines = [];
        $start = 0;
        $openDepth = 0;

        foreach ($this->kinds as $index => $kind) {
            // CommonMark keeps a prose line right after a paragraph in it, also as the lazy continuation of a quoted paragraph.
            if (MarkdownLineKind::Prose === $kind && [] !== $lines && $this->depths[$index] <= $openDepth) {
                $lines[] = $this->contents[$index];

                continue;
            }

            $paragraphs = $this->withParagraph($paragraphs, $lines, $start);
            $lines = in_array($kind, [MarkdownLineKind::Prose, MarkdownLineKind::ListItem], true) ? [$this->textOf($index)] : [];
            $start = $index;
            $openDepth = $this->depths[$index];
        }

        return $this->withParagraph($paragraphs, $lines, $start);
    }

    public function words(): int
    {
        $words = 0;

        foreach (array_keys($this->kinds) as $index) {
            $words += $this->wordsOfLine($index);
        }

        return $words;
    }

    public function summary(): ?MarkdownSummary
    {
        foreach ($this->contents as $index => $content) {
            if ($this->isOwnHeading($index) && 1 === preg_match(self::SUMMARY_HEADING, $content)) {
                return $this->summaryUnder($index);
            }
        }

        return null;
    }

    /**
     * @return array{int, string}
     */
    private function unquoted(string $line): array
    {
        $depth = 0;

        while (1 === preg_match(self::QUOTE_MARKER, $line, $marker)) {
            $line = substr($line, strlen($marker[0]));
            ++$depth;
        }

        return [$depth, rtrim($line)];
    }

    /**
     * @return array<int, true>
     */
    private function tableRows(): array
    {
        $rows = [];

        foreach (array_keys($this->contents) as $index) {
            if (isset($rows[$index])) {
                continue;
            }

            if ($this->startsATable($index)) {
                $rows += $this->tableFrom($index);

                continue;
            }

            // A framed line without a delimiter row marks only itself, so the prose after a one-row highlights fragment still counts.
            if (1 === preg_match(self::FRAMED_LINE, $this->contents[$index]) && 0 === preg_match(MarkdownLineKinds::INDENTED_CODE, $this->contents[$index])) {
                $rows[$index] = true;
            }
        }

        return $rows;
    }

    /**
     * @param list<MarkdownParagraph> $paragraphs
     * @param list<string>            $lines
     *
     * @return list<MarkdownParagraph>
     */
    private function withParagraph(array $paragraphs, array $lines, int $start): array
    {
        if ([] === $lines) {
            return $paragraphs;
        }

        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $lines)) ?? '');

        return [...$paragraphs, new MarkdownParagraph($text, $this->wordsIn($text), $start + 1)];
    }

    private function textOf(int $index): string
    {
        $content = $this->contents[$index];

        return MarkdownLineKind::ListItem === $this->kinds[$index] ? (preg_replace(MarkdownLineKinds::LIST_ITEM, '', $content) ?? $content) : $content;
    }

    private function wordsOfLine(int $index): int
    {
        return MarkdownLineKind::Code === $this->kinds[$index] ? 0 : $this->wordsIn($this->textOf($index));
    }

    private function isOwnHeading(int $index): bool
    {
        // A heading inside a quote or a list item belongs to that block, not to the page.
        return MarkdownLineKind::Heading === $this->kinds[$index] && 0 === $this->depths[$index] && !isset($this->itemHeadings[$index]);
    }

    private function summaryUnder(int $heading): MarkdownSummary
    {
        $lines = $this->sectionLines($heading);
        $text = array_filter($lines, fn (int $index): bool => MarkdownLineKind::Heading !== $this->kinds[$index], ARRAY_FILTER_USE_KEY);

        return new MarkdownSummary($this->visibleWordsIn($lines), $this->opensPageAt($heading), $this->visibleWordsIn($text) > 0);
    }

    /**
     * @return array<int, string>
     */
    private function sectionLines(int $heading): array
    {
        $level = $this->headingLevel($heading);
        $lines = [];

        foreach (array_slice($this->kinds, $heading + 1, preserve_keys: true) as $index => $kind) {
            // Only the page's own heading ends the section, so an oversized summary cannot hide behind a quoted heading.
            if ($this->isOwnHeading($index) && $this->headingLevel($index) <= $level) {
                break;
            }

            if (MarkdownLineKind::Code !== $kind) {
                $lines[$index] = $this->textOf($index);
            }
        }

        return $lines;
    }

    /**
     * @param array<int, string> $lines
     */
    private function visibleWordsIn(array $lines): int
    {
        // An HTML comment, also one over several lines or never closed, shows no text to a reader.
        return $this->wordsIn(preg_replace(self::HTML_COMMENT, ' ', implode("\n", $lines)) ?? '');
    }

    private function opensPageAt(int $heading): bool
    {
        $before = array_values(array_filter(
            array_slice($this->kinds, 0, $heading),
            static fn (MarkdownLineKind $kind): bool => MarkdownLineKind::Blank !== $kind,
        ));

        return [] === $before || [MarkdownLineKind::Heading] === $before;
    }

    private function startsATable(int $header): bool
    {
        $delimiter = $header + 1;

        if (!isset($this->contents[$delimiter]) || $this->depths[$header] !== $this->depths[$delimiter]) {
            return false;
        }

        return 1 === preg_match(self::CELL_BORDER, $this->contents[$header])
            && !$this->startsABlock($this->contents[$header])
            && 0 === preg_match(MarkdownLineKinds::INDENTED_CODE, $this->contents[$header])
            && 1 === preg_match(self::CELL_BORDER, $this->contents[$delimiter])
            && new MarkdownDelimiterRow()->delimits($this->contents[$header], $this->contents[$delimiter]);
    }

    /**
     * @return array<int, true>
     */
    private function tableFrom(int $header): array
    {
        $rows = [$header => true, $header + 1 => true];

        for ($row = $header + 2; $this->continuesTable($header, $row); ++$row) {
            $rows[$row] = true;
        }

        return $rows;
    }

    private function wordsIn(string $text): int
    {
        $tokens = preg_split('/\s+/u', trim($text), flags: PREG_SPLIT_NO_EMPTY);

        return false === $tokens ? 0 : count(array_filter($tokens, static fn (string $token): bool => 1 === preg_match(self::WORD, $token)));
    }

    private function headingLevel(int $index): int
    {
        return 1 === preg_match(MarkdownLineKinds::HEADING, $this->contents[$index], $marks) ? strlen($marks[1]) : 0;
    }

    private function startsABlock(string $content): bool
    {
        return 1 === preg_match(MarkdownLineKinds::HEADING, $content) || 1 === preg_match(MarkdownLineKinds::LIST_ITEM, $content);
    }

    private function continuesTable(int $header, int $row): bool
    {
        if (!isset($this->contents[$row]) || '' === trim($this->contents[$row])) {
            return false;
        }

        // A quoted line after a table is a quote of its own, so its words are measured.
        if ($this->depths[$row] !== $this->depths[$header]) {
            return false;
        }

        return !$this->startsABlock($this->contents[$row]) && 0 === preg_match(MarkdownLineKinds::THEMATIC_BREAK, $this->contents[$row]);
    }
}
