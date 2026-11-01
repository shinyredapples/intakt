<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

final readonly class ProjectSummary
{
    public function __construct(
        public string $name,
        public int $rounds,
        public int $judgedRounds,
    ) {
    }

    /**
     * @param list<ReviewSummary> $reviews
     *
     * @return list<self>
     */
    public static function fromReviews(array $reviews): array
    {
        $rounds = [];
        $judged = [];

        foreach ($reviews as $review) {
            $rounds[$review->project] = ($rounds[$review->project] ?? 0) + 1;
            $judged[$review->project] = ($judged[$review->project] ?? 0) + ($review->judged ? 1 : 0);
        }

        ksort($rounds, SORT_STRING);

        return array_values(array_map(
            static fn (string|int $name, int $count): self => new self((string) $name, $count, $judged[$name]),
            array_keys($rounds),
            $rounds,
        ));
    }
}
