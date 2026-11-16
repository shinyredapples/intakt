<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use DateTimeImmutable;

// A row of the review list, titled by change and round, because a round id is 36 characters a reader cannot tell apart.
final readonly class ReviewRow
{
    private const int MINUTE = 60;
    private const int HOUR = 3600;
    private const int DAY = 86400;

    public function __construct(
        public ReviewSummary $review,
        private DateTimeImmutable $now,
    ) {
    }

    public function title(): string
    {
        return sprintf('%s, round %s', '' === $this->review->change ? 'Unnamed change' : $this->review->change, $this->review->number ?? 'not numbered');
    }

    // Whole units, rounded down, so a round never reads as older than it is; a clock behind the start reads as just now.
    public function startedAgo(): string
    {
        $seconds = $this->now->getTimestamp() - $this->review->startedAt->getTimestamp();

        return match (true) {
            $seconds < self::MINUTE => 'just now',
            $seconds < self::HOUR => intdiv($seconds, self::MINUTE).' min ago',
            $seconds < self::DAY => intdiv($seconds, self::HOUR).' h ago',
            default => intdiv($seconds, self::DAY).' d ago',
        };
    }
}
