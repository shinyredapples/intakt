<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Plain code drops a finding that quotes code the change does not hold, so an invented name never reaches the judge or the act list; the symbol is place like file and line, so it is never checked, and a finding without a quote goes to the judge; a rule, not a service.
#[Exclude]
final readonly class QuotesInChange
{
    // Left out, because a fenced block is mostly a suggested fix, which the change cannot hold yet; fences count only at a line start, as in Markdown, so backticks inside a fenced line never close the block, and at any indentation and after any number of blockquote markers, because a fix under a list item or in a quote is indented or marked with it; a backtick fence's line holds no other backtick, as in Markdown, because a line that starts with an inline triple-backtick quote would otherwise hide every quote after it.
    private const string FENCED_BLOCK = '/^[ \t]*(?:>[ \t]*)*((`|~)\2{2,})(?:(?<=~)[^\n]*|[^`\n]*)(?:\n.*?)??(?:\n[ \t]*(?:>[ \t]*)*\1\2*[ \t]*$|\z)/ms';
    private const array LINE_ENDS = ["\r\n", "\r"];
    // A quote may wrap onto the next line, as in Markdown, but never across a blank line, because a paragraph ends there.
    private const string CODE = '(?<code>(?:[^`\n]|\n(?![ \t]*\n))+?)';
    // Read first, because a quote with no white space at its edges is the surest one: a lone backtick or pair in prose is then not paired with a quote's backticks, so the prose between them is never read as code.
    private const string INLINE_QUOTE = '/(?<!`)(`{1,2})(?![`\s])'.self::CODE.'(?<!\s)\1(?!`)/';
    // The white-space rule holds for single-backtick quotes only: a double-backtick quote may be padded, as Markdown asks for code that starts or ends with a backtick; the closing pair is not consumed, so a lone pair before a padded quote does not hide it.
    private const string PADDED_QUOTE = '/(?<!`)``(?!`)'.self::CODE.'(?=``(?!`))/';
    // What is left between two runs of backticks is a single-backtick quote with white space at an edge, a quote in longer or unequal runs, or prose between lone backticks, which plain code cannot tell apart, so such a span keeps a finding when it is in the change and never drops one.
    private const string UNSURE_QUOTE = '/(?<!`)`+'.self::CODE.'(?=`)/';
    // Read before single-backtick quotes are taken out, because a quote in a run of two or more backticks may hold shorter runs, which would otherwise cut the quote apart and hide its names; a run of the same length in prose may pair with the quote's run too, so such a span only keeps a finding.
    private const string QUOTE_HOLDING_BACKTICKS = '/(?<!`)(`{2,})(?!`)(?<code>(?:[^`\n]|\n(?![ \t]*\n)|`+)+?)(?=(?<!`)\1(?!`))/';
    // Put where a sure quote is taken out, so that no later span runs across it.
    private const string BLANK_LINE = "\n\n";
    private const string NAME = '/[A-Za-z_][A-Za-z0-9_]*/';
    // Left out, because a path or a file name may rightly name a file the change should add.
    private const string PATH = '~^(?:\S*[/\\\\]\S*|[\w.-]+\.[A-Za-z][A-Za-z0-9]{0,4})$~';
    private const string NUMBER = '/^[-+]?(?:0x[0-9a-f]+|\d[\d_.]*(?:e[-+]?\d+)?)$/i';

    public function reasonToDrop(Finding $finding, string $change): ?string
    {
        $text = $this->withoutFencedBlocks($finding->text);
        $rest = $this->withoutSureQuotes($text);
        $quotes = array_column($this->countedIn($text, $rest), 1);

        // Kept when any quote is found, because a quote may rightly name the fix the change still lacks.
        if ([] === $quotes || $this->anyIsInChange($quotes, $change) || $this->anyIsInChange($this->unsureQuotesIn($text, $rest), $change)) {
            return null;
        }

        return sprintf('none of its %d quoted code fragments is in the change', count($quotes));
    }

    /**
     * @return list<string>
     */
    public function countedQuotes(string $findingText): array
    {
        return array_column($this->countedQuotesWithNames($findingText), 0);
    }

    /**
     * @return list<array{string, non-empty-list<string>}>
     */
    public function countedQuotesWithNames(string $findingText): array
    {
        $text = $this->withoutFencedBlocks($findingText);

        return $this->countedIn($text, $this->withoutSureQuotes($text));
    }

    private function withoutFencedBlocks(string $text): string
    {
        // One line end for every pattern, because a closing fence before a CRLF or a CR would not be seen and the block would hide every quote after it.
        return (string) preg_replace(self::FENCED_BLOCK, '', str_replace(self::LINE_ENDS, "\n", $text));
    }

    private function withoutSureQuotes(string $text): string
    {
        return (string) preg_replace(self::INLINE_QUOTE, self::BLANK_LINE, $text);
    }

    /**
     * @return list<array{string, non-empty-list<string>}>
     */
    private function countedIn(string $text, string $rest): array
    {
        preg_match_all(self::INLINE_QUOTE, $text, $sure);
        preg_match_all(self::PADDED_QUOTE, $rest, $padded);

        return $this->checked([...$sure['code'], ...$padded['code']]);
    }

    /**
     * @param list<non-empty-list<string>> $quotes
     */
    private function anyIsInChange(array $quotes, string $change): bool
    {
        return array_any($quotes, fn (array $names): bool => $this->isInChange($names, $change));
    }

    /**
     * @return list<non-empty-list<string>>
     */
    private function unsureQuotesIn(string $text, string $rest): array
    {
        preg_match_all(self::UNSURE_QUOTE, $rest, $found);
        preg_match_all(self::QUOTE_HOLDING_BACKTICKS, $text, $holding);

        return array_column($this->checked([...$found['code'], ...$holding['code']]), 1);
    }

    /**
     * @param list<string> $quotes
     *
     * @return list<array{string, non-empty-list<string>}>
     */
    private function checked(array $quotes): array
    {
        $checked = [];

        foreach ($quotes as $quote) {
            $quote = trim($quote);
            $names = $this->namesIn($quote);

            if ([] !== $names) {
                $checked[] = [$quote, $names];
            }
        }

        return $checked;
    }

    /**
     * @return list<string>
     */
    private function namesIn(string $quote): array
    {
        if (1 === preg_match(self::PATH, $quote) || 1 === preg_match(self::NUMBER, $quote)) {
            return [];
        }

        preg_match_all(self::NAME, $quote, $names);

        return array_values(array_unique($names[0]));
    }

    /**
     * @param non-empty-list<string> $names
     */
    private function isInChange(array $names, string $change): bool
    {
        return array_any($names, static fn (string $name): bool => 1 === preg_match('/(?<![A-Za-z0-9_])'.preg_quote($name, '/').'(?![A-Za-z0-9_])/', $change));
    }
}
