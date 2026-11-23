<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// Feature rows read Feature | What | Status | Where; the Found in the beta table tracks upstream reports and is never settled.
final readonly class HighlightStatuses
{
    private const string BETA_HEADING = '## Found in the beta';
    private const int FEATURE_CELLS = 4;
    private const int STATUS = 2;
    private const int WHERE = 3;

    /**
     * @param array<int, bool> $openByNumber
     */
    public function settled(string $highlights, array $openByNumber): string
    {
        $inBeta = false;
        $lines = [];

        foreach (explode("\n", $highlights) as $line) {
            $inBeta = str_starts_with($line, '## ') ? str_starts_with($line, self::BETA_HEADING) : $inBeta;
            $lines[] = $inBeta ? $line : $this->settledRow($line, $openByNumber);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, bool> $openByNumber
     */
    private function settledRow(string $line, array $openByNumber): string
    {
        $cells = str_starts_with($line, '| ') && str_ends_with($line, ' |') ? preg_split('/(?<!\\\\)\|/', substr($line, 1, -1)) : false;

        if (false === $cells || self::FEATURE_CELLS !== count($cells) || !in_array(trim($cells[self::STATUS]), ['done', 'in progress'], true)) {
            return $line;
        }

        $status = $this->statusOf($cells[self::WHERE], $openByNumber);

        if (null === $status) {
            return $line;
        }

        $cells[self::STATUS] = ' '.$status.' ';

        return '|'.implode('|', $cells).'|';
    }

    /**
     * @param array<int, bool> $openByNumber
     */
    private function statusOf(string $where, array $openByNumber): ?string
    {
        preg_match_all('/(?<![\w\/.-])#(\d+)/', $where, $matches);
        $open = array_map(static fn (string $number): ?bool => $openByNumber[(int) $number] ?? null, $matches[1]);

        // One known open issue settles the row; a number the states lack only keeps it from reading done.
        if (in_array(true, $open, true)) {
            return 'in progress';
        }

        return [] === $open || in_array(null, $open, true) ? null : 'done';
    }
}
