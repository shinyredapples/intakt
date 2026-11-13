<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

final readonly class IssueReferences
{
    private const string WAITS_LINE = '/^\s*Waits for\s+(#\d+(?:\s*(?:,|and|&)\s*#\d+)*)\s*\.?\s*$/im';
    private const string CLOSING = '/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s*:?\s+#(\d+)\b/i';

    /**
     * @return list<int>
     */
    public function waitsFor(string $body): array
    {
        preg_match_all(self::WAITS_LINE, $body, $lines);

        return $this->numbersIn(implode(' ', $lines[1]));
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
     * @return list<int>
     */
    private function numbersIn(string $text): array
    {
        preg_match_all('/#(\d+)/', $text, $numbers);

        return array_values(array_unique(array_map(intval(...), $numbers[1])));
    }
}
