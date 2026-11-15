<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

use DateTimeImmutable;

// The merges are counted once for each version of the usage file and kept on the page, so a later merge on a usage day changes the token charts only together with the usage file.
final readonly class CountedMerges
{
    private const string KEPT = '/^<!-- merges counted for usage sha256:([0-9a-f]{64}):((?: \d{4}-\d{2}-\d{2}=\d+)*) -->$/m';

    /**
     * @param list<PullRequest> $pullRequests
     *
     * @return array<string, int>
     */
    public function perDay(string $page, string $usageDigest, Usage $usage, array $pullRequests): array
    {
        if (1 === preg_match(self::KEPT, $page, $kept) && $kept[1] === $usageDigest) {
            return $this->parsed($kept[2]);
        }

        $days = array_map(static fn (DateTimeImmutable $day): string => $day->format('Y-m-d'), new DayRange()->days(array_map(static fn (TokenUsage $row): DateTimeImmutable => $row->day, $usage->rows)));
        $merged = array_values(array_filter(array_map(static fn (PullRequest $pullRequest): ?DateTimeImmutable => $pullRequest->merged, $pullRequests)));
        $counts = array_intersect_key(array_count_values(array_map(static fn (DateTimeImmutable $day): string => $day->format('Y-m-d'), $merged)), array_flip($days));
        ksort($counts);

        return $counts;
    }

    /**
     * @param array<string, int> $perDay
     */
    public function line(string $usageDigest, array $perDay): string
    {
        return '<!-- merges counted for usage sha256:'.$usageDigest.':'.implode('', array_map(static fn (string $day, int $count): string => ' '.$day.'='.$count, array_keys($perDay), $perDay)).' -->';
    }

    /**
     * @return array<string, int>
     */
    private function parsed(string $kept): array
    {
        $counts = [];

        foreach (array_filter(explode(' ', $kept)) as $pair) {
            [$day, $count] = explode('=', $pair);
            $counts[$day] = (int) $count;
        }

        return $counts;
    }
}
