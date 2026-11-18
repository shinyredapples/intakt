<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// One home for reading a reviewer's backtick spans and emphasis marks, so a finding looks the same on every card, list and log line that shows it.
final readonly class FindingText
{
    // An opening mark follows a space, a bracket or a quote and a closing one precedes a space or punctuation, so snake_case names, arithmetic and globs keep their marks.
    private const string OPENS = '(?<=^|[\s(\[{"\'\p{Ps}\p{Pi}])';

    private const string CLOSES = '(?=$|[\s)\]}"\'\p{Pe}\p{Pf}]|[.,;:!?…]+(?:$|[\s)\]}"\'\p{Pe}\p{Pf}]))';

    // A pair never spans a blank line, whichever line ending the reviewer wrote, so stars in two paragraphs (a glob in each) never pair.
    private const string PARAGRAPH = '\R[^\S\r\n]*\R';

    // A single mark followed by a dot pairs only around one word (*.env*), so a glob like *.php never pairs with a star later in the sentence.
    private const string DOT_WORD = '\.(?:(?!\1)\S)*?';

    private const string DUNDER = '(?!__[A-Za-z0-9_]+__)';

    // A lone __word__ is a dunder name (__DIR__, __init_subclass__), so it keeps its marks; the text between a pair never holds the mark's own character, so two separate marks (*x*/*y*, two globs) never pair across each other.
    private const string STRONG = '/'.self::OPENS.self::DUNDER.'(\*\*|__)((?=[^\s\/])(?:(?!\1(?![*_])|'.self::PARAGRAPH.').)+?)(?<=[^\s\/])\1'.self::CLOSES.'/us';

    private const string PLAIN = '/'.self::OPENS.'([*_])((?=[^\s*_.\/])(?:(?!\1|'.self::PARAGRAPH.').)+?|'.self::DOT_WORD.')(?<=[^\s*_\/])\1'.self::CLOSES.'/us';

    // The cut can fall between the two characters of a closing double mark, so the one left before the ellipsis goes with the opening mark.
    private const string STRONG_CUT = '/'.self::OPENS.self::DUNDER.'(([*_])\2)((?=[^\s\/])(?:(?!\1(?![*_])).)+?)(?<=[^\s\/])\2?(?=…$)/us';

    // A single mark is also a wildcard, so it counts as cut emphasis only around one word right before the ellipsis: *test files… and *.php… keep their star.
    private const string PLAIN_CUT = '/'.self::OPENS.'([*_])((?=[^\s*_.\/])(?:(?!\1)\S)+?)(?<=[^*_\/])(?=…$)/u';

    // Double marks go before single ones, because a single-mark pattern would otherwise pair the two halves of a double mark.
    private const array EMPHASIS = [self::STRONG => '$2', self::PLAIN => '$2'];

    private const array EMPHASIS_OF_A_CUT_TITLE = [self::STRONG => '$2', self::STRONG_CUT => '$3', self::PLAIN => '$2', self::PLAIN_CUT => '$2'];

    // Plain spans never hold a backtick, so a backtick stands in for each code span while the marks around it are paired.
    private const string CODE = '`';

    public function __construct(
        private string $text,
    ) {
    }

    /**
     * @return list<array{text: string, code: bool}>
     */
    public function spans(): array
    {
        return $this->joined($this->withoutEmphasis($this->codeAndPlainSpans(), self::EMPHASIS));
    }

    // Only the caller knows that it cut the text, because an ellipsis the reviewer wrote looks the same and must close no mark.
    /**
     * @return list<array{text: string, code: bool}>
     */
    public function spansOfACutTitle(): array
    {
        return $this->joined($this->withoutEmphasis($this->codeAndPlainSpans(), self::EMPHASIS_OF_A_CUT_TITLE));
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
            $spans[] = ['text' => trim($code), 'code' => true];
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
     * @param array<string, string>                 $emphasis
     *
     * @return list<array{text: string, code: bool}>
     */
    private function withoutEmphasis(array $spans, array $emphasis): array
    {
        $codes = array_values(array_filter($spans, static fn (array $span): bool => $span['code']));
        $marked = implode('', array_map(static fn (array $span): string => $span['code'] ? self::CODE : $span['text'], $spans));
        $plain = preg_replace(array_keys($emphasis), array_values($emphasis), $marked) ?? $marked;

        $result = [];
        foreach (explode(self::CODE, $plain) as $index => $text) {
            $result[] = ['text' => $text, 'code' => false];
            if (isset($codes[$index])) {
                $result[] = $codes[$index];
            }
        }

        return $result;
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
