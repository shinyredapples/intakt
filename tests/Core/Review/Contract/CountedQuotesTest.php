<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\CountedQuotes;
use Intakt\Core\Review\Domain\QuotesInChange;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CountedQuotes::class)]
#[UsesClass(QuotesInChange::class)]
final class CountedQuotesTest extends TestCase
{
    public function testTheReviewContractReturnsTheQuotesTheQuoteCheckCounts(): void
    {
        $quotes = new CountedQuotes()->of('A stray ` appears before `ghostName`.');

        self::assertSame(['ghostName'], $quotes);
    }
}
