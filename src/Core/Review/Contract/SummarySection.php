<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// A page's "In short" section, compared whole on both sides, so a changed line inside it, a removed or added section and a renamed heading all count (docs/design/05-architecture/07-reviews.md#the-technical-writer).
final readonly class SummarySection
{
    private const string HEADING = '/^ {0,3}(#{1,6})[ \t]+(.*?)[ \t]*#*[ \t]*$/';

    private const string SUMMARY = 'in short';

    private const string FENCE_OPENS = '/^ {0,3}(`{3,}|~{3,})(.*)$/';

    // A list item, a block quote or a line indented as code, because such a line is no paragraph text a setext underline could turn into a heading.
    private const string NO_PARAGRAPH = '/^(?: {0,3}(?:[-+*]|\d{1,9}[.)])(?:[ \t]|$)| {0,3}>| {4}|\t)/';

    private const string INDENTED = '/^(?: {4}|\t)/';

    private const string FENCE_CLOSES = '/^ {0,3}(`{3,}|~{3,})[ \t]*$/';

    private const string SETEXT = '/^ {0,3}(=+|-+)[ \t]*$/';

    // An absent page is an empty one, so an added or deleted page with a summary counts as touching it.
    public function changedBetween(string $before, string $after): bool
    {
        return $this->of($before) !== $this->of($after);
    }

    // From its heading up to the next heading of the same or a higher level, because a deeper heading is part of the section.
    private function of(string $page): ?string
    {
        $lines = $this->linesOf($page);
        $start = array_find_key($lines, static fn (array $line): bool => 0 < $line[1] && self::SUMMARY === mb_strtolower($line[2]));

        if (null === $start) {
            return null;
        }

        $level = $lines[$start][1];
        $end = array_find_key(array_slice($lines, $start + 1), static fn (array $line): bool => 0 < $line[1] && $line[1] <= $level);

        return implode("\n", array_column(array_slice($lines, $start, null === $end ? null : $end + 1), 0));
    }

    /**
     * @return list<array{string, int, string, bool}>
     */
    private function linesOf(string $page): array
    {
        $split = preg_split('/\r?\n/', $page);
        $lines = [];
        $fence = null;

        foreach (false === $split ? [] : $split as $line) {
            $before = $fence;
            $fence = $this->fenceAfter($fence, $line);
            // A heading inside a code block is code, so it never opens or closes the section.
            $lines[] = null === $before && null === $fence ? $this->lineOf($line) : [$line, 0, '', false];
        }

        return $this->withSetextHeadings($this->withParagraphContinuations($lines));
    }

    // The fence a line leaves open, so the lines from an opening fence to its closing one count as code.
    private function fenceAfter(?string $fence, string $line): ?string
    {
        if (null !== $fence) {
            return $this->closes($fence, $line) ? null : $fence;
        }

        return 1 === preg_match(self::FENCE_OPENS, $line, $opening) && !$this->backtickInInfo($opening[1], $opening[2]) ? $opening[1] : null;
    }

    // Because a shorter fence inside a longer one is code, as in CommonMark (https://spec.commonmark.org/0.31.2/#fenced-code-blocks).
    private function closes(string $fence, string $line): bool
    {
        return 1 === preg_match(self::FENCE_CLOSES, $line, $closing) && $closing[1][0] === $fence[0] && strlen($closing[1]) >= strlen($fence);
    }

    // Because CommonMark reads a backtick line whose info string holds a backtick as inline code, not as a fence.
    private function backtickInInfo(string $fence, string $info): bool
    {
        return '`' === $fence[0] && str_contains($info, '`');
    }

    // Paragraph text is marked, because only such a line becomes a heading when a setext underline follows it.
    /**
     * @return array{string, int, string, bool}
     */
    private function lineOf(string $line): array
    {
        [$level, $title] = $this->headingOf($line);

        return [$line, $level, $title, 0 === $level && '' !== trim($line) && 1 !== preg_match(self::NO_PARAGRAPH, $line)];
    }

    // Because an indented line continues a paragraph, as in CommonMark, although it never starts one.
    /**
     * @param list<array{string, int, string, bool}> $lines
     *
     * @return list<array{string, int, string, bool}>
     */
    private function withParagraphContinuations(array $lines): array
    {
        foreach ($lines as $index => $line) {
            if (0 < $index && $lines[$index - 1][3] && 0 === $line[1] && '' !== trim($line[0]) && 1 === preg_match(self::INDENTED, $line[0])) {
                $lines[$index][3] = true;
            }
        }

        return $lines;
    }

    /**
     * @param list<array{string, int, string, bool}> $lines
     *
     * @return list<array{string, int, string, bool}>
     */
    private function withSetextHeadings(array $lines): array
    {
        foreach ($lines as $index => $line) {
            if (0 < $index && $lines[$index - 1][3] && 1 === preg_match(self::SETEXT, $line[0], $underline)) {
                $text = $lines[$index - 1][0];
                $lines[$index - 1] = [$text, '=' === $underline[1][0] ? 1 : 2, trim($text), false];
                $lines[$index] = [$line[0], 0, '', false];
            }
        }

        return $lines;
    }

    // Level 0 for a line that is no heading, so callers compare levels without a null check.
    /**
     * @return array{int, string}
     */
    private function headingOf(string $line): array
    {
        return 1 === preg_match(self::HEADING, $line, $found) ? [strlen($found[1]), $found[2]] : [0, ''];
    }
}
