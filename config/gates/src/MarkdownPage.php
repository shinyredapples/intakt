<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownPage
{
    public const string HEADING = '/^ {0,3}(#{1,6})(\s|$)/';
    public const string LIST_ITEM = '/^\s*([-*+]|\d+[.)])\s/';
    private const string SUMMARY_HEADING = '/^ {0,3}#{1,6}\s+In short\s*#*\s*$/i';
    private const string QUOTE_MARKERS = '/^ {0,3}(?:> ?)+/';
    private const string FRAMED_ROW = '/^ {0,3}\|.*\|\s*$/';
    private const string TABLE_DELIMITER = '/^ {0,3}\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)*\|?\s*$/';

    /**
     * @var list<string>
     */
    private array $lines;

    /**
     * @var array<int, true>
     */
    private array $quoted;

    public function __construct(string $text)
    {
        $lines = new FencedCodeBlocks()->blanked(explode("\n", str_replace("\r\n", "\n", $text)));
        $this->quoted = array_map(static fn (): bool => true, array_filter($lines, static fn (string $line): bool => 1 === preg_match(self::QUOTE_MARKERS, $line)));
        $this->lines = new IndentedCodeBlocks()->blanked(array_map(static fn (string $line): string => preg_replace(self::QUOTE_MARKERS, '', $line) ?? $line, $lines));
    }

    /**
     * @return list<string>
     */
    public static function wordsOf(string $text): array
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return false === $words ? [] : $words;
    }

    public static function wordsIn(string $text): int
    {
        return count(self::wordsOf($text));
    }

    /**
     * @return list<MarkdownParagraph>
     */
    public function paragraphs(): array
    {
        $paragraphs = [];
        $start = null;
        $block = [];
        $tableRows = $this->tableRows();

        foreach ([...$this->lines, ''] as $index => $line) {
            $prose = $this->isProse($line) && !isset($tableRows[$index]);

            if (null !== $start && (!$prose || 1 === preg_match(self::LIST_ITEM, $line))) {
                $paragraphs[] = new MarkdownParagraph($start + 1, implode(' ', $block));
                $start = null;
                $block = [];
            }

            if ($prose) {
                $start ??= $index;
                $block[] = trim($line);
            }
        }

        return $paragraphs;
    }

    public function words(): int
    {
        return self::wordsIn(preg_replace('/^ {0,3}#{1,6}(?=\s)/m', '', implode("\n", $this->lines)) ?? '');
    }

    public function breaksTheSummaryRule(): bool
    {
        return $this->words() > DocsGate::SUMMARY_THRESHOLD_WORDS && (!$this->hasSummaryFirst() || $this->summaryWords() > DocsGate::SUMMARY_WORDS);
    }

    // Because a summary only helps when the reader meets it first: nothing but blank lines may stand between the title and it.
    public function hasSummaryFirst(): bool
    {
        $summary = $this->summaryLine();

        if (null === $summary) {
            return false;
        }

        $before = array_slice($this->lines, 0, $summary);
        $title = array_find_key($before, static fn (string $line): bool => '' !== trim($line));

        if (null !== $title && 1 !== preg_match(self::HEADING, $before[$title])) {
            return false;
        }

        return [] === array_filter(array_slice($before, (int) $title + 1), static fn (string $line): bool => '' !== trim($line)) && $this->summaryWords() > 0;
    }

    public function summaryWords(): int
    {
        $summary = $this->summaryLine();

        if (null === $summary) {
            return 0;
        }

        $level = $this->levelOf($this->lines[$summary]);
        $text = [];

        foreach (array_slice($this->lines, $summary + 1) as $line) {
            $inner = $this->levelOf($line);

            if (null !== $inner && $inner <= $level) {
                break;
            }

            $text[] = null === $inner ? $line : ltrim($line, " #\t");
        }

        return self::wordsIn(implode("\n", $text));
    }

    private function summaryLine(): ?int
    {
        // Because a quoted heading belongs to the quotation, not to the page, only an unquoted "In short" is the page's summary.
        return array_find_key($this->lines, fn (string $line, int $index): bool => !isset($this->quoted[$index]) && 1 === preg_match(self::SUMMARY_HEADING, $line));
    }

    private function levelOf(string $line): ?int
    {
        return 1 === preg_match(self::HEADING, $line, $marker) ? strlen($marker[1]) : null;
    }

    /**
     * @return array<int, true>
     */
    private function tableRows(): array
    {
        $rows = [];
        $inside = false;

        foreach ($this->lines as $index => $line) {
            // https://github.github.com/gfm/#tables-extension-: a blank line or another block, such as a heading or a list, ends the table.
            if ('' === trim($line) || 1 === preg_match(self::HEADING, $line) || 1 === preg_match(self::LIST_ITEM, $line)) {
                $inside = false;
                continue;
            }

            $inside = $inside || $this->startsTable($index);

            // Because a fragment of docs/highlights.d/ holds one row without its header, a line between outer pipes is a row too, but only that line.
            if ($inside || 1 === preg_match(self::FRAMED_ROW, $line)) {
                $rows[$index] = true;
            }
        }

        return $rows;
    }

    // https://github.github.com/gfm/#tables-extension-: a header row and a delimiter row, outer pipes optional.
    private function startsTable(int $index): bool
    {
        $next = $this->lines[$index + 1] ?? '';

        return str_contains($this->lines[$index], '|') && str_contains($next, '|') && 1 === preg_match(self::TABLE_DELIMITER, $next);
    }

    private function isProse(string $line): bool
    {
        return '' !== trim($line) && 1 !== preg_match(self::HEADING, $line);
    }
}
