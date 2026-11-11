<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class SearchAnswer
{
    // A short list per group, so a long ledger cannot push the projects and pages out of sight.
    private const int MOST_PER_GROUP = 10;

    /**
     * @param list<ResultGroup> $groups
     */
    private function __construct(
        public SearchQuery $query,
        public array $groups,
        public ?string $suggestion,
        public ?SearchAction $recovery,
    ) {
    }

    /**
     * @param list<SearchResult> $results
     */
    public static function of(SearchQuery $query, array $results): self
    {
        $groups = [];

        foreach (SearchGroup::cases() as $group) {
            $inGroup = array_values(array_filter($results, static fn (SearchResult $result): bool => $group === $result->group));

            if ([] !== $inGroup) {
                $groups[] = new ResultGroup($group, array_slice($inGroup, 0, self::MOST_PER_GROUP));
            }
        }

        return new self($query, $groups, null, null);
    }

    public static function nothingFor(SearchQuery $query, string $suggestion): self
    {
        return new self($query, [], $suggestion, SearchAction::ClearSearch);
    }
}
