<?php

declare(strict_types=1);

namespace Intakt\Tests\JourneyPage;

final readonly class Usage
{
    /**
     * @param list<TokenUsage> $rows
     */
    public function __construct(public array $rows)
    {
    }

    public function total(): int
    {
        return array_sum(array_map(static fn (TokenUsage $row): int => $row->tokens, $this->rows));
    }
}
