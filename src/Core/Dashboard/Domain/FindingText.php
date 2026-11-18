<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// One home for reading a reviewer's backtick spans and emphasis marks, so a finding looks the same on every card, list and log line that shows it.
final readonly class FindingText
{
    // An opening mark follows a space, a bracket or a quote and a closing one precedes a space or punctuation, so snake_case names, arithmetic and globs keep their marks.
    private const string OPENS = '(?<=^|[\s(\[{"\'])';

    private const string CLOSES = '(?=$|[\s)\]}"\']|[.,;:!?](?:$|[\s)\]}"\']))';

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
        return $this->joined($this->withoutEmphasis($this->codeAndPlainSpans()));
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
     *
     * @return list<array{text: string, code: bool}>
     */
    private function withoutEmphasis(array $spans): array
    {
        $codes = array_values(array_filter($spans, static fn (array $span): bool => $span['code']));
        $marked = implode('', array_map(static fn (array $span): string => $span['code'] ? self::CODE : $span['text'], $spans));
        // A lone __word__ is a dunder name (__DIR__, __invoke__), so it keeps its marks; the text between a pair never holds the mark's own character, so two separate marks (*x*/*y*, two globs) never pair across each other.
        $strong = preg_replace('/'.self::OPENS.'(?!__[A-Za-z0-9]+__)(\*\*|__)(?=[^\s.\/])((?:(?!\1(?![*_])).){1,300}?)(?<=[^\s\/])\1'.self::CLOSES.'/us', '$2', $marked) ?? $marked;
        $plain = preg_replace('/'.self::OPENS.'([*_])(?=[^\s*_.\/])((?:(?!\1).){1,300}?)(?<=[^\s*_\/])\1'.self::CLOSES.'/us', '$2', $strong) ?? $strong;

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
