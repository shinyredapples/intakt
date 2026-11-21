<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Review\Domain\QuotesInChange;

// Another module shows the quotes the quote check counted through this door, so the quotes it lists are read by the check's own rule and never by a second parser.
final readonly class CountedQuotes
{
    /**
     * @return list<string>
     */
    public function of(string $findingText): array
    {
        return new QuotesInChange()->countedQuotes($findingText);
    }
}
