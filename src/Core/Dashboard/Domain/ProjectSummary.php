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
            $judged[$review->project] = ($judged[$review->project] ?? 0) + ($review->state === ReviewState::Judged ? 1 : 0);
        }

        ksort($rounds, SORT_STRING);

        $projects = [];

        foreach ($rounds as $name => $count) {
            // PHP turns a numeric project name into an integer key.
            $projects[] = new self(strval($name), $count, $judged[$name]);
        }

        return $projects;
    }
}
