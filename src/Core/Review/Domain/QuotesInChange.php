<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use Intakt\Core\Review\Contract\Finding;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Plain code drops a finding that quotes code the change does not hold, so an invented name never reaches the judge or the act list; the symbol is place like file and line, so it is never checked, and a finding without a quote goes to the judge; a rule, not a service.
#[Exclude]
final readonly class QuotesInChange
{
    // Left out, because a fenced block is mostly a suggested fix, which the change cannot hold yet; fences count only at a line start, as in Markdown, so backticks inside a fenced line never close the block, and at any indentation and after any number of blockquote markers, because a fix under a list item or in a quote is indented or marked with it.
    private const string FENCED_BLOCK = '/^[ \t]*(?:>[ \t]*)*((`|~)\2{2,})[^\n]*(?:\n.*?)??(?:\n[ \t]*(?:>[ \t]*)*\1\2*[ \t]*$|\z)/ms';
    private const array LINE_ENDS = ["\r\n", "\r"];
    private const string INLINE_QUOTE = '/(?<!`)(`{1,2})(?!`)([^`\n]+?)(?<!`)\1(?!`)/';
    private const string NAME = '/[A-Za-z_][A-Za-z0-9_]*/';
    // Left out, because a path or a file name may rightly name a file the change should add.
    private const string PATH = '~^(?:\S*[/\\\\]\S*|[\w.-]+\.[A-Za-z][A-Za-z0-9]{0,4})$~';
    private const string NUMBER = '/^[-+]?(?:0x[0-9a-f]+|\d[\d_.]*(?:e[-+]?\d+)?)$/i';

    public function reasonToDrop(Finding $finding, string $change): ?string
    {
        $quotes = $this->checkedQuotesIn($finding->text);

        // Kept when any quote is found, because a quote may rightly name the fix the change still lacks.
        if ([] === $quotes || array_any($quotes, fn (array $names): bool => $this->isInChange($names, $change))) {
            return null;
        }

        return sprintf('none of its %d quoted code fragments is in the change', count($quotes));
    }

    /**
     * @return list<non-empty-list<string>>
     */
    private function checkedQuotesIn(string $text): array
    {
        // One line end for both patterns, because a closing fence before a CRLF or a CR would not be seen and the block would hide every quote after it.
        $lines = str_replace(self::LINE_ENDS, "\n", $text);
        preg_match_all(self::INLINE_QUOTE, (string) preg_replace(self::FENCED_BLOCK, '', $lines), $found);
        $quotes = [];

        foreach ($found[2] as $quote) {
            $names = $this->namesIn(trim($quote));

            if ([] !== $names) {
                $quotes[] = $names;
            }
        }

        return $quotes;
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
