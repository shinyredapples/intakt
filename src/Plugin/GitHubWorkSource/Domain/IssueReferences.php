<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

final readonly class IssueReferences
{
    // Any line that starts with the words, after blockquote markers (`>`, repeated, with spaces or not) and a list marker (`-`, `*`, `+`, `1.` or `1)`) or not, with `**`, `__`, `*` or `_` around the words (the same mark on both sides) and the colon inside or outside, because a wait that goes unseen lets a task be built before its base. Possessive, because a long line must not stop the scan of the lines after it.
    private const string WAITS_LINE = '/^[ \t]*+((?:>[ \t]*+)*+(?:(?:[-*+]|\d{1,9}[.)])[ \t]++)?(?:(\*\*|__|\*|_)Waits[ \t]++for[ \t]*+:?\2|Waits[ \t]++for)(?=[ \t:\r]|$)[ \t]*+:?[ \t]*+(.*?))[ \t\r]*+$/i';
    // A note may follow the numbers but must hold no digit of any script, because a number Intakt skipped would be a wait it misses.
    private const string NUMBERS = '/^(#\d+(?:[ \t]*(?:,[ \t]*(?:and[ \t]+|&[ \t]*)?|and[ \t]+|&[ \t]*)#\d+)*)(?![ \t]*(?:[,&]|and\b))[^#\p{N}]*+$/iu';
    private const string EXTERNAL_OPENS = '/^[ \t]*<!-- external -->[ \t]*\r?$/';
    private const string EXTERNAL_CLOSES = '/^[ \t]*<!-- \/external -->[ \t]*\r?$/';
    private const string CLOSING = '/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s*:?\s+#(\d+)\b/i';

    /**
     * @return list<int>
     */
    public function waitsFor(string $body): array
    {
        $numbers = [];
        foreach ($this->waitLines($body) as [, $rest]) {
            if (1 === preg_match(self::NUMBERS, $rest, $list)) {
                $numbers[] = $list[1];
            }
        }

        return $this->numbersIn(implode(' ', $numbers));
    }

    /**
     * @return list<string>
     */
    public function unreadableWaits(string $body): array
    {
        $lines = [];
        foreach ($this->waitLines($body) as [$line, $rest]) {
            if (1 !== preg_match(self::NUMBERS, $rest)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return list<int>
     */
    public function closedBy(string $body): array
    {
        preg_match_all(self::CLOSING, $body, $keywords);

        return array_values(array_unique(array_map(intval(...), $keywords[1])));
    }

    /**
     * @return list<array{string, string}>
     */
    private function waitLines(string $body): array
    {
        $lines = [];
        $external = false;

        foreach (explode("\n", $body) as $line) {
            // Because copied text in an external block is untrusted even in a trusted task (the threat model), its lines record no wait until the closing marker or the end of the text.
            if ($external || 1 === preg_match(self::EXTERNAL_OPENS, $line)) {
                $external = 1 !== preg_match(self::EXTERNAL_CLOSES, $line);
                continue;
            }

            $found = preg_match(self::WAITS_LINE, $line, $wait);

            if (1 === $found) {
                $lines[] = [$wait[1], $wait[3]];
            }

            // Because a line the pattern could not finish may hold a wait, it is held as unreadable instead of dropped.
            if (false === $found) {
                $lines[] = [trim($line), ''];
            }
        }

        return $lines;
    }

    /**
     * @return list<int>
     */
    private function numbersIn(string $text): array
    {
        preg_match_all('/#(\d+)/', $text, $numbers);

        return array_values(array_unique(array_map(intval(...), $numbers[1])));
    }
}
