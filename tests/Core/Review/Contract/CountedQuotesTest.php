<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\CountedQuotes;
use Intakt\Core\Review\Contract\QuotesCounted;
use Intakt\Core\Review\Domain\QuotesInChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountedQuotes::class)]
#[UsesClass(QuotesCounted::class)]
#[UsesClass(QuotesInChange::class)]
final class CountedQuotesTest extends TestCase
{
    public function testTheReviewContractReturnsTheQuotesTheQuoteCheckCounts(): void
    {
        $quotes = new CountedQuotes()->of('A stray ` appears before `ghostName`.');

        self::assertSame(['ghostName'], iterator_to_array($quotes));
    }

    public function testTheQuotesKeepTheirOrderAndRepeats(): void
    {
        $quotes = new CountedQuotes()->of('`b` then `a` then `b`.');

        self::assertSame(['b', 'a', 'b'], iterator_to_array($quotes));
        self::assertCount(3, $quotes);
        self::assertSame(3, count($quotes));
    }
}
