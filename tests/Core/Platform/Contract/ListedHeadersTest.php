<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Contract;

use Intakt\Core\Platform\Contract\KeptTail;
use Intakt\Core\Platform\Contract\ListedHeaders;
use Intakt\Core\Platform\Contract\RedactedSpans;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListedHeaders::class)]
#[CoversClass(KeptTail::class)]
#[UsesClass(RedactedSpans::class)]
final class ListedHeadersTest extends TestCase
{
    public function testAListStartResumesAtTheEndOfItsKeptTail(): void
    {
        $listed = new ListedHeaders(new RedactedSpans(), new KeptTail(4, 9), new KeptTail(12, 20));

        self::assertSame(9, $listed->tailKeptFrom(4));
    }

    public function testTheLastKeptTailOfAListStartWins(): void
    {
        $listed = new ListedHeaders(new RedactedSpans(), new KeptTail(4, 9), new KeptTail(4, 15));

        self::assertSame(15, $listed->tailKeptFrom(4));
    }

    public function testAStartWithoutAKeptTailResumesNowhere(): void
    {
        self::assertNull(new ListedHeaders(new RedactedSpans(), new KeptTail(4, 9))->tailKeptFrom(5));
    }
}
