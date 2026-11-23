<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Domain;

use RuntimeException;

// Both the task and the pull request text are judged by what the forge renders, so text a reader never sees (an HTML comment) must not count, while a fenced block is shown but is code, not structure.
final readonly class MarkdownText
{
    // One pass from left to right, because whichever of a fence, a code span and a comment opens first makes the others' markers literal (https://spec.commonmark.org/0.31.2/#fenced-code-blocks, https://spec.commonmark.org/0.31.2/#code-spans). The pattern finds only where a part opens; each end is searched with plain string functions (`FencedBlock`, `CodeSpans`, `commentAt()`), because a pattern that walks a long part character by character exhausts the regex engine's stack on a long text, and a pattern built from a part's length is refused above the engine's largest quantifier. A fence may open right after a list marker, which then stays outside the part, and its indent is judged by `FencedBlock::opensAt()`, because inside a list item it is measured from the item's content column. The same pass tells `OpenListItems` which fences and comments it read, so the list items around a fence are found by reading each line once and never from a marker shown inside an earlier fence.
    private const string OPENING = '/^(?: {0,3}(?:[-*+]|\d{1,9}[.)])[ \t]{1,4}\K)?(?<fence>[ \t]*(?:`{3,}(?=[^`\n]*$)|~{3,}))|(?<code>`+)|(?<comment><!--)/m';

    private const string COMMENT_END = '-->';

    private const string LIST_ITEM = '/^( *)([-*+]|(\d{1,9})[.)])(?:( +)\S|( *)$)/';

    private const string ATX_HEADING = '/^ {0,3}#{1,6}(?:[ \t]|$)/';

    private const string THEMATIC_BREAK = '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/';

    private const string INDENT_AND_MARKER = '/^[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?/';

    public const int TAB_STOP = 4;

    public static function visible(string $markdown): string
    {
        return self::read($markdown, static fn (string $fence): string => $fence, static fn (string $text, int $start): string => self::commentAt($text, $start));
    }

    public static function visibleProse(string $markdown): string
    {
        return self::read($markdown, self::lineBreaksOf(...), self::taskCommentAt(...));
    }

    // A tab in the indent or after a list marker runs to the next tab stop, as Markdown reads it (https://spec.commonmark.org/0.31.2/#tabs).
    public static function withSpacesForTabs(string $line): string
    {
        return preg_replace_callback(self::INDENT_AND_MARKER, static fn (array $match): string => self::atTabStops($match[0]), $line) ?? $line;
    }

    public static function listItemColumnOf(string $line): ?int
    {
        if (self::isThematicBreak($line) || 1 !== preg_match(self::LIST_ITEM, $line, $match, PREG_UNMATCHED_AS_NULL)) {
            return null;
        }

        // Five or more spaces after the marker start indented code inside the item, so its content begins one space after the marker; an item whose marker line is empty starts its content one space after the marker too (https://spec.commonmark.org/0.31.2/#list-items).
        $spaces = strlen($match[4] ?? '');

        return strlen($match[1]) + strlen($match[2]) + (0 === $spaces || $spaces > 4 ? 1 : $spaces);
    }

    // Outside a list, an item whose marker line is empty, or an ordered item not numbered 1, does not interrupt a paragraph, so right after a paragraph line it is text (https://spec.commonmark.org/0.31.2/#list-items). The line above is a paragraph line unless it is blank, an ATX heading or a thematic break.
    public static function continuesAParagraph(string $lineAbove, string $line): bool
    {
        if (!self::isParagraphLine($lineAbove) || 1 !== preg_match(self::LIST_ITEM, $line, $match, PREG_UNMATCHED_AS_NULL)) {
            return false;
        }

        return null === $match[4] || (null !== $match[3] && 1 !== (int) $match[3]);
    }

    public static function isParagraphLine(string $line): bool
    {
        return '' !== trim($line) && 1 !== preg_match(self::ATX_HEADING, $line) && !self::isThematicBreak($line);
    }

    public static function isThematicBreak(string $line): bool
    {
        return 1 === preg_match(self::THEMATIC_BREAK, $line);
    }

    public static function lineStartAt(string $text, int $position): int
    {
        if (0 === $position) {
            return 0;
        }

        // A negative offset makes the search run backwards from the character before the position, so no copy of the text before it is needed.
        $lineBreak = strrpos($text, "\n", $position - 1 - strlen($text));

        return false === $lineBreak ? 0 : $lineBreak + 1;
    }

    public static function lineEndAt(string $text, int $position): int
    {
        return $position + strcspn($text, "\n", $position);
    }

    // `<!-->` and `<!--->` are whole comments, so the end is searched from the second character of `<!--` (https://spec.commonmark.org/0.31.2/#html-comment).
    private static function commentAt(string $text, int $start): string
    {
        $end = strpos($text, self::COMMENT_END, $start + strlen('<!'));

        return false === $end ? substr($text, $start) : substr($text, $start, $end + strlen(self::COMMENT_END) - $start);
    }

    /**
     * @param callable(string): string                     $fenced
     * @param callable(string, int, OpenListItems): string $comment
     */
    private static function read(string $markdown, callable $fenced, callable $comment): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $markdown);
        $read = '';
        $offset = 0;
        $codeSpans = CodeSpans::of($text);
        $items = OpenListItems::of($text);

        while (null !== ($match = self::firstMatch(self::OPENING, $text, $offset))) {
            $items = $items->readUpTo(self::lineStartAt($text, $match[0][1]));
            $kind = self::kindOf($text, $match, $items);
            $start = self::startOf($kind, $text, $match[0][1]);
            $part = match ($kind) {
                MarkdownPart::Fence, MarkdownPart::FenceAfterAMarker => FencedBlock::at($text, $start, $items),
                MarkdownPart::Code => ($codeSpans = $codeSpans->inTheParagraphAt($start))->spanOrLiteralAt($start, $offset),
                MarkdownPart::Literal => $match['fence'][0],
                default => $comment($text, $start, $items),
            };
            $read .= substr($text, $offset, $start - $offset).self::shown($kind, $part, $fenced);
            $offset = $start + strlen($part);

            // A code span or a literal run shows its lines as text, so only the lines of a fence or a comment hold no list items.
            if (MarkdownPart::Code !== $kind && MarkdownPart::Literal !== $kind) {
                $items = $items->passOver($start, $offset);
            }
        }

        return $read.substr($text, $offset);
    }

    // Hidden and fenced text keeps its line breaks, because the lines around it keep their place.
    private static function lineBreaksOf(string $text): string
    {
        return str_repeat("\n", substr_count($text, "\n"));
    }

    private static function taskCommentAt(string $text, int $start, OpenListItems $items): string
    {
        return $items->readUpTo(self::lineStartAt($text, $start) + 1)->opensAnHtmlBlock($start) ? self::htmlBlockAt($text, $start) : self::commentAt($text, $start);
    }

    private static function atTabStops(string $prefix): string
    {
        return array_reduce(
            str_split($prefix),
            static fn (string $expanded, string $character): string => $expanded.("\t" === $character ? str_repeat(' ', self::TAB_STOP - strlen($expanded) % self::TAB_STOP) : $character),
            '',
        );
    }

    // An unreadable text must stop the check, because reading it as empty would pass a task's Evidence list unseen.
    /**
     * @return array<array{string, int}>|null
     */
    private static function firstMatch(string $pattern, string $text, int $offset): ?array
    {
        return match (preg_match($pattern, $text, $match, PREG_OFFSET_CAPTURE, $offset)) {
            1 => $match,
            0 => null,
            false => throw new RuntimeException('the Markdown text cannot be read: '.preg_last_error_msg()),
        };
    }

    /**
     * @param array<array{string, int}> $match
     */
    private static function kindOf(string $text, array $match, OpenListItems $items): MarkdownPart
    {
        $start = $match[0][1];
        $fence = $match['fence'][0] ?? '';

        return match (true) {
            '' === $fence => '' !== ($match['code'][0] ?? '') ? MarkdownPart::Code : MarkdownPart::Comment,
            FencedBlock::opensAt($text, $start, $items) => $start > 0 && "\n" !== $text[$start - 1] ? MarkdownPart::FenceAfterAMarker : MarkdownPart::Fence,
            str_ends_with($fence, '`') => MarkdownPart::Code,
            default => MarkdownPart::Literal,
        };
    }

    // A backtick run indented too far to open a fence still opens or closes a code span, so the part starts at the run, not at the indent.
    private static function startOf(MarkdownPart $kind, string $text, int $match): int
    {
        return $match + (MarkdownPart::Code === $kind ? strspn($text, " \t", $match) : 0);
    }

    /**
     * @param callable(string): string $fenced
     */
    private static function shown(MarkdownPart $kind, string $part, callable $fenced): string
    {
        return match ($kind) {
            MarkdownPart::FenceAfterAMarker => self::afterTheOpeningLine($part, $fenced),
            MarkdownPart::Fence => $fenced(self::withoutTheInfoString($part)),
            MarkdownPart::Code, MarkdownPart::Literal => $part,
            default => self::lineBreaksOf($part),
        };
    }

    // In a task, a comment whose `<!--` opens its line is an HTML block up to the end of the line holding its `-->`, so the text after it on that line is no paragraph line and the next line continues no paragraph (https://spec.commonmark.org/0.31.2/#html-blocks, kind 2). In a body that text is shown, so a reference there still counts.
    private static function htmlBlockAt(string $text, int $start): string
    {
        return substr($text, $start, self::lineEndAt($text, $start + strlen(self::commentAt($text, $start))) - $start);
    }

    // The opening line of a fence after a list marker stays, because the marker still starts an item.
    /**
     * @param callable(string): string $fenced
     */
    private static function afterTheOpeningLine(string $fence, callable $fenced): string
    {
        $fence = self::withoutTheInfoString($fence);
        $lineEnd = strcspn($fence, "\n");

        return substr($fence, 0, $lineEnd).$fenced(substr($fence, $lineEnd));
    }

    // A fence's info string is not shown, so a reference there does not count (https://spec.commonmark.org/0.31.2/#info-string).
    private static function withoutTheInfoString(string $fence): string
    {
        $indent = strspn($fence, " \t");

        return substr($fence, 0, $indent + strspn($fence, $fence[$indent], $indent)).substr($fence, strcspn($fence, "\n"));
    }
}
