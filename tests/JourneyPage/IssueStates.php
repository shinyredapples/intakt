<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// bin/journey-record derives this file from the recorded issues, which hold pull requests too (merged ones as closed),
// so one file answers for every number a Where cell names; the journey's own issues.json is never needed here.
final readonly class IssueStates
{
    public const string FILE = 'issue-states.json';

    /**
     * @return array<int, bool>
     */
    public function openByNumber(string $recordedFolder): array
    {
        $json = @file_get_contents($recordedFolder.'/'.self::FILE);
        $items = false === $json ? null : json_decode($json, true);
        $states = [];

        foreach (is_array($items) ? $items : [] as $item) {
            $number = is_array($item) ? ($item['number'] ?? null) : null;
            $state = is_array($item) ? ($item['state'] ?? null) : null;

            if (is_int($number) && in_array($state, ['open', 'closed'], true)) {
                $states[$number] = 'open' === $state;
            }
        }

        return $states;
    }
}
