<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Contract;

use Intakt\Core\Review\Contract\StreamChunks;
use Intakt\Core\Review\Contract\StreamCutBy;
use Intakt\Core\Review\Contract\StreamEndedEarly;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(StreamChunks::class)]
#[CoversClass(StreamEndedEarly::class)]
final class StreamChunksTest extends TestCase
{
    public function testTheChunksIterateInTheOrderTheyWereGiven(): void
    {
        self::assertSame(['TokenUsage', 'TextDelta "a"'], [...new StreamChunks('TokenUsage', 'TextDelta "a"')]);
    }

    public function testChunksSpreadFromAStringKeyedArrayIterateAsAList(): void
    {
        self::assertSame(['TokenUsage', 'TextDelta "a"'], [...new StreamChunks(...['first' => 'TokenUsage', 'second' => 'TextDelta "a"'])]);
    }

    public function testNoChunksAreEmpty(): void
    {
        self::assertTrue(new StreamChunks()->isEmpty());
    }

    public function testAChunkMakesThemNotEmpty(): void
    {
        self::assertFalse(new StreamChunks('TokenUsage')->isEmpty());
    }

    public function testACutDescribesItsLastChunksSeparatedByCommas(): void
    {
        $cut = new StreamEndedEarly('cut', StreamCutBy::Platform, 2, new StreamChunks('TokenUsage', 'TextDelta "a"'));

        self::assertSame("cut by: platform\nchunks: 2\nlast chunks: TokenUsage, TextDelta \"a\"", $cut->described());
    }

    public function testACutWithoutChunksDescribesThemAsNone(): void
    {
        $cut = new StreamEndedEarly('cut', StreamCutBy::Platform, 0, new StreamChunks());

        self::assertSame("cut by: platform\nchunks: 0\nlast chunks: none", $cut->described());
    }
}
