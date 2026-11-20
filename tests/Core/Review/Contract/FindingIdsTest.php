<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\ListedFindingIds;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FindingIds::class)]
final class FindingIdsTest extends TestCase
{
    public function testTheIdsAreIteratedInTheOrderTheyWereGiven(): void
    {
        self::assertSame(['F2', 'F1'], [...new ListedFindingIds('F2', 'F1')]);
    }

    public function testIdsGivenWithStringKeysAreIteratedAsAList(): void
    {
        self::assertSame(['F1', 'F2'], [...new ListedFindingIds(...['a' => 'F1', 'b' => 'F2'])]);
    }

    public function testAGivenIdIsContained(): void
    {
        self::assertTrue(new ListedFindingIds('F1', 'F2')->contains('F2'));
    }

    public function testAnIdNotGivenIsNotContained(): void
    {
        self::assertFalse(new ListedFindingIds('F1')->contains('F10'));
    }

    public function testTheIdsEncodeAsAJsonList(): void
    {
        self::assertSame('{"merged":["F1","F2"]}', json_encode(['merged' => new ListedFindingIds('F1', 'F2')], JSON_THROW_ON_ERROR));
    }

    public function testNoIdsEncodeAsAnEmptyJsonList(): void
    {
        self::assertSame('[]', json_encode(new ListedFindingIds(), JSON_THROW_ON_ERROR));
    }
}
