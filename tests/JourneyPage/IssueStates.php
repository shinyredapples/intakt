<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// The recording holds issues and pull requests alike (merged ones as closed), so one file answers for every number a Where cell names.
final readonly class IssueStates
{
    /**
     * @return array<int, bool> issue or pull request number => still open; empty when the recording is missing or unreadable
     */
    public function openByNumber(string $recordedFolder): array
    {
        $json = @file_get_contents($recordedFolder.'/'.JourneyResource::Issues->value.'.json');
        $pages = false === $json ? null : json_decode($json, true);
        $states = [];

        foreach (is_array($pages) ? $pages : [] as $page) {
            foreach (is_array($page) ? $page : [] as $item) {
                $number = is_array($item) ? ($item['number'] ?? null) : null;
                $state = is_array($item) ? ($item['state'] ?? null) : null;

                if (is_int($number) && in_array($state, ['open', 'closed'], true)) {
                    $states[$number] = 'open' === $state;
                }
            }
        }

        return $states;
    }
}
