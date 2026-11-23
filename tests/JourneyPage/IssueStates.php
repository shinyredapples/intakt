<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

// A file of its own, because a missing state file must keep the statuses as written without failing the journey page.
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

        foreach (is_array($items) ? array_filter($items, is_array(...)) : [] as $item) {
            if ($this->isState($item)) {
                $states[$item['number']] = 'open' === $item['state'];
            }
        }

        return $states;
    }

    /**
     * @param array<mixed> $item
     *
     * @phpstan-assert-if-true array{number: int, state: 'open'|'closed'} $item
     */
    private function isState(array $item): bool
    {
        return is_int($item['number'] ?? null) && in_array($item['state'] ?? null, ['open', 'closed'], true);
    }
}
