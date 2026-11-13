<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

final readonly class IssueReferences
{
    // Any line that starts with the words, in a list or not, because a wait that goes unseen lets a task be built before its base.
    private const string WAITS_LINE = '/^[ \t]*((?:>[ \t]*)*(?:[-*+][ \t]+)?[*_]*Waits[ \t]+for\b[*_]*[ \t]*:?[*_]*[ \t]*(.*?))[ \t\r]*$/im';
    // A note may follow the numbers but must name no other number, because a number Intakt skipped would be a wait it misses.
    private const string NUMBERS = '/^(#\d+(?:[ \t]*(?:,[ \t]*(?:and[ \t]+|&[ \t]*)?|and[ \t]+|&[ \t]*)#\d+)*)(?![ \t]*(?:[,&\d]|and\b|then[ \t]+\d))[^#]*$/i';
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
        preg_match_all(self::WAITS_LINE, $body, $lines, PREG_SET_ORDER);

        return array_map(static fn (array $line): array => [$line[1], $line[2]], $lines);
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
