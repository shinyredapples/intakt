<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// One home for reading a reviewer's backtick spans and emphasis marks, so a finding looks the same on every card, list and log line that shows it.
final readonly class FindingText
{
    // Plain spans never hold a backtick, so a backtick stands in for each code span while the marks around it are paired.
    private const string CODE = '`';

    // A code span holding a blank line still parts two paragraphs, so its stand-in carries one.
    private const string BLANK_LINE = "\n\n";

    private const string CODE_OF_PARAGRAPHS = self::BLANK_LINE.self::CODE;

    // An opening mark follows a space, a bracket, a quote or a code span and a closing one precedes a space, punctuation, a dash (**read**-only) or a code span, so snake_case names, arithmetic and globs keep their marks; because of the dash, a glob star before a dash still pairs with a later star in its paragraph (*-test.php and build*-old).
    private const string OPENS = '(?<=^|[\s(\[{"\'`\p{Ps}\p{Pi}])';

    private const string CLOSES = '(?=$|[\s)\]}"\'`\p{Pe}\p{Pf}\p{Pd}]|[.,;:!?…]+(?:$|[\s)\]}"\'`\p{Pe}\p{Pf}\p{Pd}]))';

    // A pair never spans a blank line, whichever line ending the reviewer wrote, so stars in two paragraphs (a glob in each) never pair.
    private const string PARAGRAPH = '\R[^\S\r\n]*\R';

    // A single mark followed by a dot pairs only around one word (*.env*), so a glob like *.php never pairs with a star later in the sentence, and *.* keeps both.
    private const string DOT_WORD = '\.(?:(?![*_])\S)+?';

    private const string DUNDER = '(?!__[A-Za-z0-9_]+__)';

    // A lone __word__ is a dunder name (__DIR__, __init_subclass__), so it keeps its marks; the text between a pair never holds the mark's own character, so two separate marks (*x*/*y*, two globs) never pair across each other.
    private const string STRONG = '/'.self::OPENS.self::DUNDER.'(\*\*|__)((?=[^\s\/])(?:(?!\1(?![*_])|'.self::PARAGRAPH.').)+?)(?<=[^\s\/])\1'.self::CLOSES.'/us';

    private const string PLAIN = '/'.self::OPENS.'([*_])((?=[^\s.\/])(?:(?!\1|'.self::PARAGRAPH.').)+?|'.self::DOT_WORD.')(?<=[^\s\/])\1'.self::CLOSES.'/us';

    // Double marks go before single ones, because a single-mark pattern would otherwise pair the two halves of a double mark; the second round reads emphasis nested one level deep (*_both_*, **outer *inner* words**), whose inner marks open or close next to the outer ones.
    private const array EMPHASIS = [self::STRONG, self::PLAIN, self::STRONG, self::PLAIN];

    private const string ELLIPSIS = '…';

    public function __construct(
        private string $text,
    ) {
    }

    /**
     * @return list<array{text: string, code: bool}>
     */
    public function spans(): array
    {
        return $this->joined($this->withoutEmphasis($this->codeAndPlainSpans()));
    }

    // Cut after the marks are read, so a cut never leaves half a mark or a mark without its partner; by characters, so a multibyte character is never split, and ending in an ellipsis so a reader sees the text goes on.
    /**
     * @return list<array{text: string, code: bool}>
     */
    public function spansCutTo(int $characters): array
    {
        $spans = $this->spans();
        if (mb_strlen(implode('', array_column($spans, 'text'))) <= $characters) {
            return $spans;
        }

        $cut = [];
        $left = $characters - mb_strlen(self::ELLIPSIS);
        foreach ($spans as $span) {
            if ($left <= 0) {
                break;
            }

            $text = mb_substr($span['text'], 0, $left);
            $left -= mb_strlen($text);
            $cut[] = ['text' => $text, 'code' => $span['code']];
        }

        $last = array_pop($cut);
        while (null !== $last && '' === rtrim($last['text'])) {
            $last = array_pop($cut);
        }

        if (null !== $last) {
            $cut[] = ['text' => rtrim($last['text']), 'code' => $last['code']];
        }

        return $this->joined([...$cut, ['text' => self::ELLIPSIS, 'code' => false]]);
    }

    /**
     * @return list<array{text: string, code: bool}>
     */
    private function codeAndPlainSpans(): array
    {
        // A span closes on a run of as many backticks as opened it, as in Markdown, so ``a`b`` holds one backtick of code.
        preg_match_all('/(?<!`)(`+)(?!`)(.+?)(?<!`)\1(?!`)/s', $this->text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $spans = [];
        $at = 0;
        foreach ($matches as [[$whole, $start], , [$code]]) {
            array_push($spans, ...$this->plainSpans(substr($this->text, $at, $start - $at)));
            // Trimmed only once the marks are read, so a blank line at its edge still parts two paragraphs.
            $spans[] = ['text' => $code, 'code' => true];
            $at = $start + strlen($whole);
        }

        array_push($spans, ...$this->plainSpans(substr($this->text, $at)));

        return $spans;
    }

    /**
     * @return list<array{text: string, code: bool}>
     */
    private function plainSpans(string $text): array
    {
        // A backtick that closes nothing still means code to the reviewer, so it marks the word after it; with no word after it, it is dropped, so no card shows a literal backtick outside a code span.
        preg_match_all('/`+([^\s`]*)/', $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $spans = [];
        $at = 0;
        foreach ($matches as [[$whole, $start], [$word]]) {
            $spans[] = ['text' => substr($text, $at, $start - $at), 'code' => false];
            $spans[] = ['text' => $word, 'code' => true];
            $at = $start + strlen($whole);
        }

        $spans[] = ['text' => substr($text, $at), 'code' => false];

        return $spans;
    }

    /**
     * @param list<array{text: string, code: bool}> $spans
     *
     * @return list<array{text: string, code: bool}>
     */
    private function withoutEmphasis(array $spans): array
    {
        $codes = array_values(array_filter($spans, static fn (array $span): bool => $span['code']));
        $marked = implode('', array_map($this->standIn(...), $spans));
        $plain = preg_replace(self::EMPHASIS, '$2', $marked) ?? $marked;

        $result = [];
        foreach (explode(self::CODE, $plain) as $index => $text) {
            if (isset($codes[$index]) && $this->holdsAParagraph($codes[$index])) {
                $text = substr($text, 0, -strlen(self::BLANK_LINE));
            }

            $result[] = ['text' => $text, 'code' => false];
            if (isset($codes[$index])) {
                $result[] = ['text' => trim($codes[$index]['text']), 'code' => true];
            }
        }

        return $result;
    }

    /**
     * @param array{text: string, code: bool} $span
     */
    private function standIn(array $span): string
    {
        if (!$span['code']) {
            return $span['text'];
        }

        return $this->holdsAParagraph($span) ? self::CODE_OF_PARAGRAPHS : self::CODE;
    }

    /**
     * @param array{text: string, code: bool} $span
     */
    private function holdsAParagraph(array $span): bool
    {
        return 1 === preg_match('/'.self::PARAGRAPH.'/u', $span['text']);
    }

    /**
     * @param list<array{text: string, code: bool}> $spans
     *
     * @return list<array{text: string, code: bool}>
     */
    private function joined(array $spans): array
    {
        $joined = [];
        foreach ($spans as $span) {
            if ('' === $span['text']) {
                continue;
            }

            $last = array_key_last($joined);
            if (null !== $last && !$span['code'] && !$joined[$last]['code']) {
                $joined[$last]['text'] .= $span['text'];
                continue;
            }

            $joined[] = $span;
        }

        return $joined;
    }
}
