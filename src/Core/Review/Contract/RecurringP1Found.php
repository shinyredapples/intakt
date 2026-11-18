<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Both rounds and both findings by id, because the finding's text and place are in each round's judge_done already.
final readonly class RecurringP1Found
{
    public function __construct(
        public string $round,
        public int $number,
        public string $finding,
        public string $earlierRound,
        public int $earlierNumber,
        public string $earlierFinding,
        public string $file,
        public ?string $symbol,
        // Null when the finding names no regular file of the checkout, so it is judged again instead of matching a kept verdict.
        public ?string $key,
    ) {
    }
}
