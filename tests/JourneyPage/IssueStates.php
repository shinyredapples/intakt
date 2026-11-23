<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// The recording holds issues and pull requests alike (merged ones as closed), so one file answers for every number a Where cell names.
final readonly class IssueStates
{
    /**
     * @return array<int, bool>
     */
    public function openByNumber(string $recordedFolder): array
    {
        $json = @file_get_contents($recordedFolder.'/'.JourneyResource::Issues->value.'.json');
        $pages = false === $json ? null : json_decode($json, true);
        $states = [];

        foreach ($this->items(is_array($pages) ? $pages : []) as $item) {
            $number = $item['number'] ?? null;
            $state = $item['state'] ?? null;

            if (is_int($number) && in_array($state, ['open', 'closed'], true)) {
                $states[$number] = 'open' === $state;
            }
        }

        return $states;
    }

    /**
     * @param array<mixed> $pages
     *
     * @return list<array<mixed>>
     */
    private function items(array $pages): array
    {
        $items = array_merge([], ...array_map(static fn (mixed $page): array => is_array($page) ? array_values($page) : [], $pages));

        return array_values(array_filter($items, is_array(...)));
    }
}
