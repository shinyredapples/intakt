<?php

declare(strict_types=1);

namespace Intakt\Gates;

final readonly class MarkdownPage
{
    private const string HEADING = '/^ {0,3}#{1,6}(\s|$)/';
    private const string SUMMARY_HEADING = '/^ {0,3}#{1,6}\s+In short\s*#*\s*$/i';
    private const string LIST_ITEM = '/^\s*([-*+]|\d+[.)])\s/';
    private const string TABLE_ROW = '/^\s*\|/';

    /**
     * @var list<string>
     */
    private array $lines;

    public function __construct(string $text)
    {
        $this->lines = new FencedCodeBlocks()->blanked(explode("\n", str_replace("\r\n", "\n", $text)));
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

        foreach ([...$this->lines, ''] as $index => $line) {
            if (null !== $start && $this->endsBlockBefore($line)) {
                $paragraphs[] = new MarkdownParagraph($start + 1, implode(' ', $block));
                $start = null;
                $block = [];
            }

            if ($this->isProse($line)) {
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

    public function hasSummaryFirst(): bool
    {
        return 1 === preg_match(self::SUMMARY_HEADING, $this->headings()[1] ?? '') || 1 === preg_match(self::SUMMARY_HEADING, $this->headings()[0] ?? '');
    }

    public function summaryWords(): int
    {
        $inside = false;
        $text = [];

        foreach ($this->lines as $line) {
            if (1 === preg_match(self::HEADING, $line)) {
                $inside = 1 === preg_match(self::SUMMARY_HEADING, $line);
                continue;
            }

            if ($inside) {
                $text[] = $line;
            }
        }

        return self::wordsIn(implode("\n", $text));
    }

    /**
     * @return list<string>
     */
    private function headings(): array
    {
        return array_values(array_filter($this->lines, static fn (string $line): bool => 1 === preg_match(self::HEADING, $line)));
    }

    private function endsBlockBefore(string $line): bool
    {
        return '' === trim($line) || !$this->isProse($line) || 1 === preg_match(self::LIST_ITEM, $line);
    }

    private function isProse(string $line): bool
    {
        return '' !== trim($line) && 1 !== preg_match(self::HEADING, $line) && 1 !== preg_match(self::TABLE_ROW, $line);
    }
}
